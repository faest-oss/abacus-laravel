<?php

declare(strict_types=1);

namespace Faest\Abacus;

use Exception;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Data\Append;
use Faest\Abacus\Data\GenericPayload;
use Faest\Abacus\Data\LedgerTransferResult;
use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Data\Transaction;
use Faest\Abacus\Data\TransactionDraft;
use Faest\Abacus\Data\VoidDraft;
use Faest\Abacus\Exceptions\FailedInvariantException;
use Faest\Abacus\Exceptions\IllegalVoidException;
use Faest\Abacus\Exceptions\UnexpectedStreamVersionException;
use Faest\Abacus\Models\LedgerTransaction;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;

abstract class AbstractLedger
{
    private ?string $connectionOverride = null;

    private ?int $lockTimeout = null;

    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * @return array<mixed>|JsonSerializable
     */
    abstract public function initializeAggregate(): array|JsonSerializable;

    /**
     * @param  array<mixed>|JsonSerializable  $existingAggregate
     * @return array<mixed>|JsonSerializable
     */
    abstract public function applyToAggregate(
        LedgerPayload $ledgerPayload,
        array|JsonSerializable $existingAggregate,
    ): array|JsonSerializable;

    /**
     * @param  array<mixed>|JsonSerializable  $aggregate
     */
    abstract public function assertInvariants(LedgerPayload $ledgerPayload, array|JsonSerializable $aggregate): void;

    abstract public function getLedgerType(): string;

    abstract public function getPayloadType(LedgerPayload $payload): string;

    /**
     * @param  array<mixed>  $payload
     */
    public function deserialize(string $eventType, array $payload): LedgerPayload
    {
        return GenericPayload::make($eventType, $payload);
    }

    private function toDto(LedgerTransaction $model): Transaction
    {
        return new Transaction($model->id, $model->stream_version);
    }

    abstract public function computeOpposing(LedgerPayload $payload): LedgerPayload;

    /**
     * @param  array<mixed>  $desired
     * @param  array<mixed>  $existing
     * @return array<mixed>
     */
    // abstract public function computeDelta(array $desired, array $existing): array;

    /**
     * @return array<mixed>|JsonSerializable
     */
    public function getAggregate(string $ledgerId): array|JsonSerializable
    {
        /* @var Collection<int, LedgerTransaction> $history */
        $history = $this->ledgerTranQuery()->where('ledger_type', $this->getLedgerType())
            ->where('ledger_id', $ledgerId)
            ->orderBy('stream_version', 'asc')
            ->get();

        $aggregate = $this->initializeAggregate();

        foreach ($history as $historyEntry) {
            /** @var LedgerTransaction $historyEntry */
            $aggregate = $this->applyToAggregate($this->deserialize(
                $historyEntry->payload_type,
                $historyEntry->payload,
            ), $aggregate);
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
    final public function postMany(array $drafts, ?PostingContext $context = null): array
    {
        $context ??= PostingContext::forUser();

        if (count($drafts) === 1) {
            $context = $context->withCorrelationId(null);
        }

        $appends = [];

        foreach ($drafts as $draft) {
            $append = Append::make($draft, $context);

            $appends[] = $append;
        }

        return $this->writeMany($appends);
    }

    public function streamVersion(string $ledgerId): int
    {
        return $this->conn()->table('ledger_stream_head')->where([
            'ledger_type' => $this->getLedgerType(),
            'ledger_id' => $ledgerId,
        ])->first()->version ?? 0;
    }


    public function void(VoidDraft $draft, ?PostingContext $context = null): Transaction
    {
        $context ??= PostingContext::forUser();
        $transactionToReverse = $this->ledgerTranQuery()->findSole($draft->transactionId);

        if ($transactionToReverse->ledger_type !== $this->getLedgerType()) {
            throw new IllegalVoidException("Transaction {$draft->transactionId}
                does not belong to ledger {$this->getLedgerType()}");
        }

        $payload = $this->deserialize($transactionToReverse->payload_type, $transactionToReverse->payload);

        return $this->write(
            Append::make(
                TransactionDraft::make(
                    $this->getLedgerType(),
                    $transactionToReverse->ledger_id,
                    $this->computeOpposing($payload)
                ),
                $context
            )->reverses($transactionToReverse->id)
        );
    }

    public function transfer(
        LedgerPayload $payload,
        string $sourceLedgerId,
        string $destinationLedgerId,
        ?PostingContext $context = null
    ): LedgerTransferResult {
        $context ??= PostingContext::forUser();

        $source = TransactionDraft::make($this->getLedgerType(), $sourceLedgerId, $payload);
        $dest = TransactionDraft::make($this->getLedgerType(), $destinationLedgerId, $this->computeOpposing($payload));

        $results =  $this->writeMany([
            Append::make($source, $context),
            Append::make($dest, $context),
        ]);

        return new LedgerTransferResult($context->correlationId, $results[0], $results[1]);
    }

    private function conn(): Connection
    {
        return DB::connection($this->connectionOverride);
    }

    /**
     * @return Builder<LedgerTransaction>
     */
    private function ledgerTranQuery(): Builder
    {
        return LedgerTransaction::on($this->connectionOverride);
    }

    public function overrideConnection(string $connection): void
    {
        $this->connectionOverride = $connection;
    }

    public function overrideLockTimeout(?int $timeout): void
    {
        $this->lockTimeout = $timeout;
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

        usort($streams, fn($a, $b) => $a <=> $b);

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
    * @param array<int, array<int, string>> $streams
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

            $heads[$stream[0]][$stream[1]] = $q->first()->version;
        }

        return $heads;
    }

    /**
     * @param  array<int, Append>  $appends
     * @param array<string, array<string, int>> $heads
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
     * @param array<string, array<string, int>> $heads
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

            $aggregate = $aggregates[$append->ledgerType][$append->ledgerId] ?? null;

            if (! $aggregate) {
                $aggregate = $this->getAggregate($append->ledgerId);
            }

            try {
                $this->assertInvariants($append->payload, $aggregate);
            } catch (Exception $e) {
                throw new FailedInvariantException($e->getMessage(), $append->source, previous: $e);
            }

            $aggregates[$append->ledgerType][$append->ledgerId] = $this->applyToAggregate(
                $append->payload,
                $aggregate
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
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Ledger operations must run within a db trannsaction');
        }

        $completed = [];

        foreach ($plannedAppends as $append) {
            $newEntry = new LedgerTransaction();
            $newEntry->setConnection($this->connectionOverride);
            $newEntry->entered_by_user_id = $append->actor;
            $newEntry->recorded_at = $append->systemDate->toImmutable();
            $newEntry->reverses_transaction_id = $append->reversesId;
            //$newEntry->adjusts_transaction_id = $append->adjustsId;
            $newEntry->correlation_id = $append->correlationId;
            $newEntry->payload_type = $append->payload->payloadType();
            $newEntry->ledger_type = $append->ledgerType;
            $newEntry->ledger_id = $append->ledgerId;
            $newEntry->effective_at = $append->eventDate->toImmutable();
            $newEntry->accounting_date = $append->accountingDate->toImmutable();
            $newEntry->payload = $append->payload->jsonSerialize();
            $newEntry->reason = $append->reason;
            $newEntry->stream_version = $append->version;
            $newEntry->save();

            $this->conn()->table('ledger_stream_head')->where([
                'ledger_type' => $this->getLedgerType(),
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
}
