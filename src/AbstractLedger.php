<?php

declare(strict_types=1);

namespace Faest\Abacus;

use Carbon\CarbonImmutable;
use Exception;
use Faest\Abacus\Models\LedgerTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
     * @param  array<mixed>|JsonSerializable  $entry
     * @param  array<mixed>|JsonSerializable  $existingAggregate
     * @return array<mixed>|JsonSerializable
     */
    abstract public function applyToAggregate(
        array|JsonSerializable $entry,
        array|JsonSerializable $existingAggregate,
    ): array|JsonSerializable;

    /**
     * @param  array<mixed>|JsonSerializable  $entry
     * @param  array<mixed>|JsonSerializable  $aggregate
     */
    abstract public function assertInvariants(array|JsonSerializable $entry, array|JsonSerializable $aggregate): void;

    abstract public function getLedgerType(): string;

    /**
     * @param  array<mixed>|JsonSerializable  $payload
     */
    abstract public function getLedgerId(array|JsonSerializable $payload): string;

    /**
     * @param  array<mixed>|JsonSerializable  $payload
     */
    abstract public function getPayloadType(array|JsonSerializable $payload): string;

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>|JsonSerializable
     */
    public function deserialize(string $eventType, array $payload): array|JsonSerializable
    {
        return $payload;
    }

    /**
     * @param  array<mixed>|JsonSerializable  $entry
     * @return array<mixed>|JsonSerializable
     */
    abstract public function computeOpposing(array|JsonSerializable $entry): array|JsonSerializable;

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
            $aggregate = $this->applyToAggregate($historyEntry->payload, $aggregate);
        }

        return $aggregate;
    }

    /**
     * @param  array<mixed>|JsonSerializable  $record
     */
    public function post(
        array|JsonSerializable $record,
        string $reason,
        CarbonImmutable $effectiveAt,
    ): LedgerTransaction {
        return DB::connection()->transaction(fn () => $this->performPost($record, $reason, $effectiveAt));
    }

    /**
     * @param  array<mixed>|JsonSerializable  $record
     */
    private function performPost(
        array|JsonSerializable $record,
        string $reason,
        CarbonImmutable $effectiveAt,
        ?string $reversesId = null,
        ?string $adjustsId = null,
    ): LedgerTransaction {
        DB::connection()->table('ledger_transaction_type_id')->upsert([
            'ledger_type' => $this->getLedgerType(),
            'ledger_id' => $this->getLedgerId($record),
        ], ['ledger_type', 'ledger_id']);

        if (DB::transactionLevel() === 0) {
            throw new LogicException('Ledger operations must run within a db transaction');
        }

        DB::connection()->table('ledger_transaction_type_id')
            ->where('ledger_type', $this->getLedgerType())
            ->where('ledger_id', $this->getLedgerId($record))
            ->lockForUpdate()
            ->get();

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
            ->where('ledger_id', $this->getLedgerId($record))
            ->orderBy('id', 'asc')
            ->get();

        $aggregate = $this->initializeAggregate();

        foreach ($history as $historyEntry) {
            /** @var LedgerTransaction $historyEntry */
            $aggregate = $this->applyToAggregate($historyEntry->payload, $aggregate);
        }

        $this->assertInvariants($record, $aggregate);

        $authId = Auth::id();

        if (! $authId) {
            throw new Exception('Ledgers updates must be made by authenticated actors');
        }

        $newEntry = new LedgerTransaction;
        $newEntry->entered_by_user_id = (string) $authId;
        $newEntry->recorded_at = now()->toImmutable();
        $newEntry->reverses_transaction_id = $reversesId;
        $newEntry->adjusts_transaction_id = $adjustsId;
        $newEntry->payload_type = $this->getPayloadType($record);
        $newEntry->ledger_type = $this->getLedgerType();
        $newEntry->ledger_id = $this->getLedgerId($record);
        $newEntry->effective_at = $effectiveAt;
        $newEntry->payload = $record instanceof JsonSerializable
            ? $record->jsonSerialize()
            : $record;
        $newEntry->reason = $reason;
        $newEntry->save();

        return $newEntry;
    }

    public function void(string $id, string $reason, ?CarbonImmutable $effectiveAt = null): LedgerTransaction
    {
        $transactionToReverse = LedgerTransaction::query()->findSole($id);

        return DB::connection()->transaction(fn () => $this->performPost(
            $this->computeOpposing($this->deserialize($transactionToReverse->payload_type, $transactionToReverse->payload)),
            $reason,
            $effectiveAt ? $effectiveAt : $transactionToReverse->effective_at,
            reversesId: $id,
        ));
    }
}
