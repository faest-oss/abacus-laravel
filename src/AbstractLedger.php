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
        return new Transaction($model->id);
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
            ->orderBy('id', 'asc')
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
        $this->setupLockRecords([$ledgerId]);

        return DB::connection()->transaction(fn () => $this->performPost(
            $ledgerId, $payload, $reason, $effectiveAt));
    }

    /**
     * @param  array<int, string>  $ledgerIds
     */
    private function setupLockRecords(array $ledgerIds): void
    {
        foreach ($ledgerIds as $id) {
            DB::connection()->table('ledger_stream_head')->upsert([
                'ledger_type' => $this->getLedgerType(),
                'ledger_id' => $id,
            ], ['ledger_type', 'ledger_id']);
        }
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
            ->orderBy('id', 'asc')
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
        $newEntry->payload = $draft->payload->jsonSerialize();
        $newEntry->reason = $draft->reason;
        $newEntry->save();

        return $this->toDto($newEntry);
    }

    public function void(string $id, string $reason, ?CarbonImmutable $effectiveAt = null): Transaction
    {
        $transactionToReverse = LedgerTransaction::query()->findSole($id);
        $payload = $this->deserialize($transactionToReverse->payload_type, $transactionToReverse->payload);
        $this->setupLockRecords([$transactionToReverse->ledger_id]);

        return DB::connection()->transaction(fn () => $this->performPost(
            $transactionToReverse->ledger_id,
            $this->computeOpposing($payload),
            $reason,
            $effectiveAt ? $effectiveAt : $transactionToReverse->effective_at,
            reversesId: $id,
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

        return DB::connection()->transaction(function () use ($payload, $sourceLedgerId, $destinationLedgerId, $effectiveAt, $reason) {
            $this->lockLedgers([$sourceLedgerId, $destinationLedgerId]);
            $correlationId = (string) Str::orderedUuid();
            $source = $this->performPost($sourceLedgerId, $payload, $reason, $effectiveAt, correlationId: $correlationId);
            $dest = $this->performPost($destinationLedgerId, $this->computeOpposing($payload), $reason, $effectiveAt, correlationId: $correlationId);

            return new LedgerTransferResult($correlationId, $source, $dest);
        });
    }
}
