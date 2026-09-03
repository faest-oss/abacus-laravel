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
use Faest\Abacus\Exceptions\IllegalVoidException;
use Faest\Abacus\Exceptions\UnexpectedStreamVersionException;
use Faest\Abacus\Models\LedgerTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonSerializable;
use LogicException;

abstract class AbstractLedger
{
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
        $history = LedgerTransaction::query()->where('ledger_type', $this->getLedgerType())
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

        return DB::connection()->transaction(fn () => $this->performPost(
            $draft,
        ));
    }

    public function streamVersion(string $ledgerId): int
    {
        return DB::connection()->table('ledger_stream_head')->where([
            'ledger_type' => $this->getLedgerType(),
            'ledger_id' => $ledgerId,
        ])->first()->version ?? 0;
    }

    /**
     * @param  array<int, string>  $ledgerIds
     */
    private function setupLockRecords(array $ledgerIds): void
    {
        $pairs = [];
        foreach ($ledgerIds as $id) {
            $pairs[] = ['ledger_type' => $this->getLedgerType(), 'ledger_id' => $id];
        }

        DB::connection()->table('ledger_stream_head')->insertOrIgnore($pairs);
    }

    /**
     * @param  array<int, string>  $ledgerIds
     */
    private function lockLedgers(array $ledgerIds): void
    {
        sort($ledgerIds);
        foreach ($ledgerIds as $id) {
            DB::connection()->table('ledger_stream_head')
                ->where('ledger_type', $this->getLedgerType())
                ->where('ledger_id', $id)
                ->lockForUpdate()
                ->get();
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

        $this->lockLedgers([$draft->ledgerId]);

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
            $existingReversal = LedgerTransaction::query()
                ->where('reverses_transaction_id', $reversesId)
                ->first();

            if ($existingReversal) {
                throw new Exception('This transaction has already been reversed');
            }
        }

        if ($adjustsId) {
            $existingAdjustment = LedgerTransaction::query()
                ->where('adjusts_transaction_id', $adjustsId)
                ->first();

            if ($existingAdjustment) {
                throw new Exception('This transaction has already been adjusted');
            }
        }

        /* @var Collection<int, LedgerTransaction> $history */
        $history = LedgerTransaction::query()->where('ledger_type', $this->getLedgerType())
            ->where('ledger_id', $draft->ledgerId)
            ->orderBy('stream_version', 'asc')
            ->get();

        $aggregate = $this->getAggregate($draft->ledgerId);
        $this->assertInvariants($draft->payload, $aggregate);

        $authId = $draft->userId ? $draft->userId : Auth::id();

        if (! $authId) {
            throw new Exception('Ledgers updates must be made by authenticated actors');
        }

        $newEntry = new LedgerTransaction;
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

        DB::connection()->table('ledger_stream_head')->where([
            'ledger_type' => $this->getLedgerType(),
            'ledger_id' => $draft->ledgerId,
        ])->update(['version' => $nextVersion]);

        return $this->toDto($newEntry);
    }

    public function void(VoidDraft $draft): Transaction
    {
        $transactionToReverse = LedgerTransaction::query()->findSole($draft->transactionId);

        if ($transactionToReverse->ledger_type !== $this->getLedgerType()) {
            throw new IllegalVoidException("Transaction {$draft->transactionId}
                does not belong to ledger {$this->getLedgerType()}");
        }
        $payload = $this->deserialize($transactionToReverse->payload_type, $transactionToReverse->payload);

        $this->setupLockRecords([$transactionToReverse->ledger_id]);

        return DB::connection()->transaction(fn () => $this->performPost(
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
        $this->setupLockRecords([$sourceLedgerId, $destinationLedgerId]);

        $correlationId = (string) Str::orderedUuid();

        $source = TransactionDraft::make($sourceLedgerId, $payload)
            ->correlateUsing($correlationId)
            ->occurredAt($effectiveAt)
            ->withReason($reason);

        $dest = TransactionDraft::make($destinationLedgerId, $this->computeOpposing($payload))
            ->correlateUsing($correlationId)
            ->occurredAt($effectiveAt)
            ->withReason($reason);

        return DB::connection()->transaction(function () use ($source, $dest, $correlationId) {
            $this->lockLedgers([$source->ledgerId, $dest->ledgerId]);
            $sourceResult = $this->performPost($source, correlationId: $correlationId);
            $destResult = $this->performPost($dest, correlationId: $correlationId);

            return new LedgerTransferResult($source->correlationId, $sourceResult, $destResult);
        });
    }
}
