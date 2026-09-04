<?php

declare(strict_types=1);

namespace Faest\Abacus;

use Carbon\CarbonImmutable;
use Exception;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Data\GenericPayload;
use Faest\Abacus\Data\LedgerTransferResult;
use Faest\Abacus\Data\Transaction;
use Faest\Abacus\Data\TransactionDraft;
use Faest\Abacus\Data\VoidDraft;
use Faest\Abacus\Exceptions\FailedInvariantException;
use Faest\Abacus\Exceptions\IllegalVoidException;
use Faest\Abacus\Exceptions\UnexpectedStreamVersionException;
use Faest\Abacus\Models\LedgerTransaction;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public function post(TransactionDraft $draft): Transaction
    {
        $this->setupLockRecords([$draft->ledgerId]);

        return $this->conn()->transaction(function () use ($draft) {
            $this->lockLedgers([$draft->ledgerId]);

            return $this->performPost($draft);
        });
    }

    /**
     * @param  array<int, TransactionDraft>  $drafts
     * @return array<int, Transaction>
     */
    final public function postMany(array $drafts): array
    {
        $ledgerIds = collect($drafts)->pluck('ledgerId')->all();
        $this->setupLockRecords($ledgerIds);

        return $this->conn()->transaction(function () use ($drafts, $ledgerIds) {
            $this->lockLedgers($ledgerIds);
            $results = [];
            foreach ($drafts as $draft) {
                $results[] = $this->performPost($draft);
            }

            return $results;
        });
    }

    public function streamVersion(string $ledgerId): int
    {
        return $this->conn()->table('ledger_stream_head')->where([
            'ledger_type' => $this->getLedgerType(),
            'ledger_id' => $ledgerId,
        ])->first()->version ?? 0;
    }

    /**
     * @param  array<int, string>  $ledgerIds
     */
    private function setupLockRecords(array $ledgerIds): void
    {
        $ledgerIds = array_unique($ledgerIds);
        usort($ledgerIds, fn (string $a, string $b): int => strnatcmp($a, $b));

        $pairs = [];
        foreach ($ledgerIds as $id) {
            $pairs[] = ['ledger_type' => $this->getLedgerType(), 'ledger_id' => $id];
        }

        $this->conn()->table('ledger_stream_head')->insertOrIgnore($pairs);
    }

    /**
     * @param  array<int, string>  $ledgerIds
     */
    private function lockLedgers(array $ledgerIds): void
    {
        $ledgerIds = array_unique($ledgerIds);
        usort($ledgerIds, fn (string $a, string $b): int => strnatcmp($a, $b));

        if ($this->lockTimeout) {
            $this->conn()->statement("SET statement_timeout = {$this->lockTimeout}");
        }

        foreach ($ledgerIds as $id) {
            $q = $this->conn()->table('ledger_stream_head')
                ->where('ledger_type', $this->getLedgerType())
                ->where('ledger_id', $id);

            if ($this->lockTimeout === 0) {
                $q = $q->lock('for update nowait');
            } else {
                $q = $q->lockForUpdate();
            }

            $q->get();
        }
    }

    private function performPost(
        TransactionDraft $draft,
        ?string $reversesId = null,
        ?string $adjustsId = null,
        ?string $correlationId = null,
    ): Transaction {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Ledger operations must run within a db transaction');
        }

        if ($draft->expectedVersion !== null && $draft->expectedVersion < 0) {
            throw new InvalidArgumentException('Expected version must not be negative');
        }

        $currentVersion = $this->streamVersion($draft->ledgerId);

        if ($draft->expectedVersion !== null && $draft->expectedVersion !== $currentVersion) {
            throw new UnexpectedStreamVersionException(
                'Stream expectation failed',
                $this->getLedgerType(),
                $draft->ledgerId,
                $draft->expectedVersion,
                $currentVersion,
            );
        }

        $nextVersion = $currentVersion + 1;

        if ($reversesId) {
            $existingReversal = $this->ledgerTranQuery()
                ->where('reverses_transaction_id', $reversesId)
                ->first();

            if ($existingReversal) {
                throw new Exception('This transaction has already been reversed');
            }
        }

        if ($adjustsId) {
            $existingAdjustment = $this->ledgerTranQuery()
                ->where('adjusts_transaction_id', $adjustsId)
                ->first();

            if ($existingAdjustment) {
                throw new Exception('This transaction has already been adjusted');
            }
        }

        $aggregate = $this->getAggregate($draft->ledgerId);

        try {
            $this->assertInvariants($draft->payload, $aggregate);
        } catch (Exception $e) {
            throw new FailedInvariantException($e->getMessage(), $draft, previous: $e);
        }

        $authId = $draft->userId ? $draft->userId : Auth::id();

        if (! $authId) {
            throw new Exception('Ledgers updates must be made by authenticated actors');
        }

        $newEntry = new LedgerTransaction;
        $newEntry->setConnection($this->connectionOverride);
        $newEntry->entered_by_user_id = (string) $authId;
        $newEntry->recorded_at = now()->toImmutable();
        $newEntry->reverses_transaction_id = $reversesId;
        $newEntry->adjusts_transaction_id = $adjustsId;
        $newEntry->correlation_id = $correlationId;
        $newEntry->payload_type = $this->getPayloadType($draft->payload);
        $newEntry->ledger_type = $this->getLedgerType();
        $newEntry->ledger_id = $draft->ledgerId;
        $newEntry->effective_at = $draft->eventDate->toImmutable();
        $newEntry->accounting_date = $draft->accountingDate->toImmutable();
        $newEntry->payload = $draft->payload->jsonSerialize();
        $newEntry->reason = $draft->reason;
        $newEntry->stream_version = $nextVersion;
        $newEntry->save();

        $this->conn()->table('ledger_stream_head')->where([
            'ledger_type' => $this->getLedgerType(),
            'ledger_id' => $draft->ledgerId,
        ])->update(['version' => $nextVersion]);

        return $this->toDto($newEntry);
    }

    public function void(VoidDraft $draft): Transaction
    {
        $transactionToReverse = $this->ledgerTranQuery()->findSole($draft->transactionId);

        if ($transactionToReverse->ledger_type !== $this->getLedgerType()) {
            throw new IllegalVoidException("Transaction {$draft->transactionId}
                does not belong to ledger {$this->getLedgerType()}");
        }
        $payload = $this->deserialize($transactionToReverse->payload_type, $transactionToReverse->payload);

        $this->setupLockRecords([$transactionToReverse->ledger_id]);

        return $this->conn()->transaction(fn () => $this->performPost(
            TransactionDraft::make(
                $transactionToReverse->ledger_id,
                $this->computeOpposing($payload),
            )
                ->withReason($draft->reason)
                ->occurredAt($draft->eventDate)
                ->bookedFor($draft->accountingDate)
                ->failIfVersionIsnt($draft->expectedVersion)
                ->correlateUsing($draft->correlationId),
            reversesId: $draft->transactionId,
        ));
    }

    public function transfer(
        LedgerPayload $payload,
        string $sourceLedgerId,
        string $destinationLedgerId,
        CarbonImmutable $effectiveAt,
        string $reason,
    ): LedgerTransferResult {
        $correlationId = (string) Str::orderedUuid();

        $source = TransactionDraft::make($sourceLedgerId, $payload)
            ->correlateUsing($correlationId)
            ->occurredAt($effectiveAt)
            ->withReason($reason);

        $dest = TransactionDraft::make($destinationLedgerId, $this->computeOpposing($payload))
            ->correlateUsing($correlationId)
            ->occurredAt($effectiveAt)
            ->withReason($reason);

        $this->setupLockRecords([$source->ledgerId, $dest->ledgerId]);

        return $this->conn()->transaction(function () use ($source, $dest, $correlationId) {
            $this->lockLedgers([$source->ledgerId, $dest->ledgerId]);
            $sourceResult = $this->performPost($source, correlationId: $correlationId);
            $destResult = $this->performPost($dest, correlationId: $correlationId);

            return new LedgerTransferResult($source->correlationId, $sourceResult, $destResult);
        });
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
}
