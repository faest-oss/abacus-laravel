<?php

declare(strict_types=1);

namespace Faest\Abacus;

use Closure;
use Exception;
use Faest\Abacus\Contracts\Ledger;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Data\Append;
use Faest\Abacus\Data\LedgerTransferResult;
use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Data\Transaction;
use Faest\Abacus\Data\TransactionDraft;
use Faest\Abacus\Data\VoidDraft;
use Faest\Abacus\Exceptions\FailedInvariantException;
use Faest\Abacus\Exceptions\IdempotencyConflictException;
use Faest\Abacus\Exceptions\UnexpectedStreamVersionException;
use Faest\Abacus\Models\LedgerTransaction;
use Faest\Abacus\Support\PayloadFingerprint;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;

final class Abacus
{
    /** @var array<string, Ledger> */
    private array $ledgerRegistry = [];

    private ?string $connectionOverride = null;

    private ?int $lockTimeout = null;

    public function registerLedger(Ledger $ledger): self
    {
        $this->ledgerRegistry[$ledger->getLedgerType()] = $ledger;
        $this->ledgerRegistry[$ledger::class] = $ledger;

        return $this;
    }

    public function resolveLedger(string $ledgerType): Ledger
    {
        if (isset($this->ledgerRegistry[$ledgerType])) {
            return $this->ledgerRegistry[$ledgerType];
        }

        if (class_exists($ledgerType) && is_subclass_of($ledgerType, Ledger::class)) {
            /** @var Ledger $instance */
            $instance = app($ledgerType);
            $this->registerLedger($instance);

            return $instance;
        }

        throw new InvalidArgumentException("Unregistered or invalid ledger type: {$ledgerType}");
    }

    public function findTransaction(string $transactionId): ?LedgerTransaction
    {
        return $this->ledgerTranQuery()->find($transactionId);
    }

    /**
     * @return array<mixed>|JsonSerializable
     */
    public function getAggregate(string $ledgerType, string $ledgerId): array|JsonSerializable
    {
        $ledger = $this->resolveLedger($ledgerType);

        /** @var Collection<int, LedgerTransaction> $history */
        $history = $this->ledgerTranQuery()
            ->where('ledger_type', $ledger->getLedgerType())
            ->where('ledger_id', $ledgerId)
            ->orderBy('stream_version', 'asc')
            ->get();

        $aggregate = $ledger->initializeAggregate();

        foreach ($history as $historyEntry) {
            $aggregate = $ledger->applyToAggregate(
                $ledger->deserialize(
                    $historyEntry->payload_type,
                    $historyEntry->payload,
                ),
                $aggregate,
            );
        }

        return $aggregate;
    }

    public function post(TransactionDraft $draft, ?PostingContext $context = null): Transaction
    {
        return $this->postMany([$draft], $context)[0];
    }

    /**
     * @param  array<int, TransactionDraft>  $drafts
     * @return array<int, Transaction>
     */
    public function postMany(array $drafts, ?PostingContext $context = null): array
    {
        $context ??= PostingContext::forUser();

        if (count($drafts) > 1 && $context->correlationId === null) {
            $context = $context->withCorrelationId((string) Str::orderedUuid());
        } elseif (count($drafts) === 1 && $context->correlationId === null) {
            $context = $context->withCorrelationId(null);
        }

        $appends = [];

        foreach ($drafts as $draft) {
            $appends[] = Append::make($draft, $context);
        }

        return $this->writeMany($appends);
    }

    /**
     * Coordinate multiple ledger operations using a bundle builder.
     *
     * @param  PostingContext|Closure(BundleBuilder): void  $contextOrCallback
     * @param  (Closure(BundleBuilder): void)|null  $callback
     * @return array<int, Transaction>
     */
    public function bundle(PostingContext|Closure $contextOrCallback, ?Closure $callback = null): array
    {
        if ($contextOrCallback instanceof Closure) {
            $callback = $contextOrCallback;
            $context = PostingContext::forUser();
        } else {
            $context = $contextOrCallback;
        }

        if ($callback === null) {
            throw new InvalidArgumentException('A bundle callback must be provided');
        }

        $builder = new BundleBuilder($this);
        $callback($builder);

        return $this->postMany($builder->getDrafts(), $context);
    }

    public function streamVersion(string $ledgerType, string $ledgerId): int
    {
        $head = $this->conn()->table('ledger_stream_head')->where([
            'ledger_type' => $ledgerType,
            'ledger_id' => $ledgerId,
        ])->first();

        return $head ? (int) $head->version : 0;
    }

    public function void(VoidDraft $draft, ?PostingContext $context = null): Transaction
    {
        $context ??= PostingContext::forUser();
        $transactionToReverse = $this->ledgerTranQuery()->findSole($draft->transactionId);

        $ledger = $this->resolveLedger($transactionToReverse->ledger_type);
        $payload = $ledger->deserialize($transactionToReverse->payload_type, $transactionToReverse->payload);

        return $this->write(
            Append::make(
                TransactionDraft::make(
                    $transactionToReverse->ledger_type,
                    $transactionToReverse->ledger_id,
                    $ledger->computeOpposing($payload),
                )->failIfVersionIsnt($draft->expectedVersion),
                $context,
            )->reverses($transactionToReverse->id),
        );
    }

    public function reverse(string $transactionId, ?PostingContext $context = null): Transaction
    {
        return $this->void(VoidDraft::make($transactionId), $context);
    }

    /**
     * @return array<int, Transaction>
     */
    public function reverseOperation(string $correlationId, ?PostingContext $context = null): array
    {
        $transactions = $this->ledgerTranQuery()
            ->where('correlation_id', $correlationId)
            ->whereNull('reverses_transaction_id')
            ->get();

        if ($transactions->isEmpty()) {
            throw new InvalidArgumentException("No unreversed transactions found for operation: {$correlationId}");
        }

        $context ??= PostingContext::forUser();
        $reversalDrafts = [];

        foreach ($transactions as $tx) {
            $ledger = $this->resolveLedger($tx->ledger_type);
            $payload = $ledger->deserialize($tx->payload_type, $tx->payload);
            $opposing = $ledger->computeOpposing($payload);

            $reversalDrafts[] = TransactionDraft::make(
                $tx->ledger_type,
                $tx->ledger_id,
                $opposing,
            )->reverses($tx->id);
        }

        return $this->postMany($reversalDrafts, $context);
    }

    public function transfer(
        LedgerPayload $payload,
        string $sourceLedgerId,
        string $destinationLedgerId,
        string $ledgerType,
        ?PostingContext $context = null,
    ): LedgerTransferResult {
        $context ??= PostingContext::forUser();
        $correlationId = $context->correlationId ?? (string) Str::orderedUuid();
        $context = $context->withCorrelationId($correlationId);

        $ledger = $this->resolveLedger($ledgerType);
        $opposingPayload = $ledger->computeOpposing($payload);

        $sourceDraft = TransactionDraft::make($ledgerType, $sourceLedgerId, $payload);
        $destDraft = TransactionDraft::make($ledgerType, $destinationLedgerId, $opposingPayload);

        $results = $this->postMany([$sourceDraft, $destDraft], $context);

        return new LedgerTransferResult($correlationId, $results[0], $results[1]);
    }

    public function overrideConnection(string $connection): void
    {
        $this->connectionOverride = $connection;
    }

    public function overrideLockTimeout(?int $timeout): void
    {
        $this->lockTimeout = $timeout;
    }

    private function conn(): Connection
    {
        return DB::connection($this->connectionOverride);
    }

    /**
     * @return Builder<LedgerTransaction>
     */
    public function ledgerTranQuery(): Builder
    {
        return LedgerTransaction::on($this->connectionOverride);
    }

    private function toDto(LedgerTransaction $model): Transaction
    {
        return new Transaction($model->id, $model->stream_version);
    }

    /**
     * @param  array<int, Append>  $appends
     * @return array<int, array<int, string>>
     */
    private function normalizeStreams(array $appends): array
    {
        $streams = [];
        foreach ($appends as $append) {
            if ($append->expectedVersion !== null && $append->expectedVersion < 0) {
                throw new InvalidArgumentException('Expected version must not be negative');
            }

            $streams[] = [$append->ledgerType, $append->ledgerId];
        }

        usort($streams, function (array $a, array $b): int {
            return strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]);
        });

        return array_values(array_unique($streams, SORT_REGULAR));
    }

    /**
     * @param  array<int, array<int, string>>  $streams
     */
    private function prepareStreamHeads(array $streams): void
    {
        $pairs = [];
        foreach ($streams as $stream) {
            $pairs[] = ['ledger_type' => $stream[0], 'ledger_id' => $stream[1]];
        }

        $this->conn()->table('ledger_stream_head')->insertOrIgnore($pairs);
    }

    /**
     * @param  array<int, array<int, string>>  $streams
     * @return array<string, array<string, int>>
     */
    private function lockStreamHeads(array $streams): array
    {
        $heads = [];

        if ($this->lockTimeout) {
            $this->conn()->statement("SET statement_timeout = {$this->lockTimeout}");
        }

        foreach ($streams as $stream) {
            $q = $this->conn()->table('ledger_stream_head')
                ->where('ledger_type', $stream[0])
                ->where('ledger_id', $stream[1]);

            if ($this->lockTimeout === 0) {
                $q = $q->lock('for update nowait');
            } else {
                $q = $q->lockForUpdate();
            }

            $heads[$stream[0]][$stream[1]] = (int) $q->first()->version;
        }

        return $heads;
    }

    /**
     * @param  array<int, Append>  $appends
     * @param  array<string, array<string, int>>  $heads
     */
    private function assertExpectedVersions(array $appends, array $heads): void
    {
        foreach ($appends as $append) {
            $currentVersion = $heads[$append->ledgerType][$append->ledgerId];

            if ($append->expectedVersion !== null && $append->expectedVersion !== $currentVersion) {
                throw new UnexpectedStreamVersionException(
                    'Stream expectation failed',
                    $append->ledgerType,
                    $append->ledgerId,
                    $append->expectedVersion,
                    $currentVersion,
                );
            }
        }
    }

    /**
     * @param  array<int, Append>  $appends
     * @param  array<string, array<string, int>>  $heads
     * @return array<int, Append>
     */
    private function planAppends(array $appends, array $heads): array
    {
        $planned = [];
        $aggregates = [];

        foreach ($appends as $append) {
            $reversesId = $append->reversesId;

            if ($reversesId) {
                $existingReversal = $this->ledgerTranQuery()
                    ->where('reverses_transaction_id', $reversesId)
                    ->first();

                if ($existingReversal) {
                    throw new Exception('This transaction has already been reversed');
                }
            }

            $ledger = $this->resolveLedger($append->ledgerType);

            $aggregate = $aggregates[$append->ledgerType][$append->ledgerId] ?? null;

            if ($aggregate === null) {
                $aggregate = $this->getAggregate($append->ledgerType, $append->ledgerId);
            }

            try {
                $ledger->assertInvariants($append->payload, $aggregate);
            } catch (Exception $e) {
                throw new FailedInvariantException($e->getMessage(), $append->source, previous: $e);
            }

            $aggregates[$append->ledgerType][$append->ledgerId] = $ledger->applyToAggregate(
                $append->payload,
                $aggregate,
            );

            $currentVersion = $heads[$append->ledgerType][$append->ledgerId];
            $nextVersion = $currentVersion + 1;

            $planned[] = $append->withVersion($nextVersion);
            $heads[$append->ledgerType][$append->ledgerId] = $nextVersion;
        }

        return $planned;
    }

    /**
     * @param  array<int, Append>  $plannedAppends
     * @return array<int, Transaction>
     */
    private function persistAppends(array $plannedAppends): array
    {
        if ($this->conn()->transactionLevel() === 0) {
            throw new LogicException('Ledger operations must run within a db transaction');
        }

        $completed = [];

        foreach ($plannedAppends as $append) {
            $newEntry = new LedgerTransaction();
            $newEntry->setConnection($this->connectionOverride);
            $newEntry->actor = $append->actor;
            $newEntry->system_date = $append->systemDate->toImmutable();
            $newEntry->reverses_transaction_id = $append->reversesId;
            // $newEntry->adjusts_transaction_id = $append->adjustsId;
            $newEntry->correlation_id = $append->correlationId;
            $newEntry->idempotency_key = $append->idempotencyKey;
            $newEntry->payload_type = $append->payload->payloadType();
            $newEntry->ledger_type = $append->ledgerType;
            $newEntry->ledger_id = $append->ledgerId;
            $newEntry->event_date = $append->eventDate->toImmutable();
            $newEntry->accounting_date = $append->accountingDate->toImmutable();
            $newEntry->payload = $append->payload->jsonSerialize();
            $newEntry->reason = $append->reason;
            $newEntry->stream_version = $append->version;
            $newEntry->save();

            $this->conn()->table('ledger_stream_head')->where([
                'ledger_type' => $append->ledgerType,
                'ledger_id' => $append->ledgerId,
            ])->update(['version' => $append->version]);

            $completed[] = $this->toDto($newEntry);
        }

        return $completed;
    }

    /**
     * @param  array<int, Append>  $appends
     * @return array<int, Transaction>
     */
    private function writeMany(array $appends): array
    {
        $replayed = $this->checkIdempotency($appends);

        if ($replayed !== null) {
            return $replayed;
        }

        $streams = $this->normalizeStreams($appends);
        $this->prepareStreamHeads($streams);

        return $this->conn()->transaction(function () use ($appends, $streams) {
            $heads = $this->lockStreamHeads($streams);

            $this->assertExpectedVersions($appends, $heads);

            $planned = $this->planAppends($appends, $heads);

            return $this->persistAppends($planned);
        });
    }

    private function write(Append $append): Transaction
    {
        return $this->writeMany([$append])[0];
    }

    /**
     * @param  array<int, Append>  $appends
     * @return ?array<int, Transaction>
     */
    private function checkIdempotency(array $appends): ?array
    {
        // Extract non-null idempotency keys
        $keys = array_filter(array_map(fn(Append $a) => $a->idempotencyKey, $appends));

        if (empty($keys)) {
            return null; // No idempotency keys provided, proceed with normal write
        }

        // Query existing transactions for these keys
        $existing = $this->ledgerTranQuery()
            ->whereIn('idempotency_key', $keys)
            ->orderBy('stream_version', 'asc')
            ->get();

        if ($existing->isEmpty()) {
            return null; // First-time write
        }

        // If key count doesn't match or draft count differs -> conflict
        if ($existing->count() !== count($appends)) {
            throw new IdempotencyConflictException(
                $appends[0]->ledgerType,
                $keys[0],
                'Idempotent batch size mismatch.'
            );
        }

        // Verify each append matches the existing transaction
        foreach ($appends as $index => $append) {
            $tx = $existing[$index];

            $targetMismatch = $tx->ledger_type !== $append->ledgerType
                || $tx->ledger_id !== $append->ledgerId
                || $tx->payload_type !== $append->payload->payloadType();

            $payloadMismatch = ! PayloadFingerprint::matches($append->payload, $tx->payload);

            if ($targetMismatch || $payloadMismatch) {
                throw new IdempotencyConflictException(
                    $append->ledgerType,
                    $append->idempotencyKey,
                );
            }
        }

        // Exact replay: map existing records to Transaction DTOs and return
        return $existing->map(fn(LedgerTransaction $tx) => $this->toDto($tx))->all();
    }
}
