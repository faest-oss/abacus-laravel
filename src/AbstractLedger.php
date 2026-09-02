<?php

declare(strict_types=1);

namespace Faest\Abacus;

use Carbon\CarbonImmutable;
use Exception;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Data\GenericPayload;
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

    abstract public function getLedgerId(LedgerPayload $payload): string;

    abstract public function getPayloadType(LedgerPayload $payload): string;

    /**
     * @param  array<mixed>  $payload
     */
    public function deserialize(string $eventType, array $payload): LedgerPayload
    {
        return GenericPayload::make($eventType, $payload);
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

    public function post(
        LedgerPayload $payload,
        string $reason,
        CarbonImmutable $effectiveAt,
    ): LedgerTransaction {
        return DB::connection()->transaction(fn () => $this->performPost($payload, $reason, $effectiveAt));
    }

    private function performPost(
        LedgerPayload $payload,
        string $reason,
        CarbonImmutable $effectiveAt,
        ?string $reversesId = null,
        ?string $adjustsId = null,
    ): LedgerTransaction {
        DB::connection()->table('ledger_transaction_type_id')->upsert([
            'ledger_type' => $this->getLedgerType(),
            'ledger_id' => $this->getLedgerId($payload),
        ], ['ledger_type', 'ledger_id']);

        if (DB::transactionLevel() === 0) {
            throw new LogicException('Ledger operations must run within a db transaction');
        }

        DB::connection()->table('ledger_transaction_type_id')
            ->where('ledger_type', $this->getLedgerType())
            ->where('ledger_id', $this->getLedgerId($payload))
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
            ->where('ledger_id', $this->getLedgerId($payload))
            ->orderBy('id', 'asc')
            ->get();

        $aggregate = $this->getAggregate($this->getLedgerId($payload));
        $this->assertInvariants($payload, $aggregate);

        $authId = Auth::id();

        if (! $authId) {
            throw new Exception('Ledgers updates must be made by authenticated actors');
        }

        $newEntry = new LedgerTransaction;
        $newEntry->entered_by_user_id = (string) $authId;
        $newEntry->recorded_at = now()->toImmutable();
        $newEntry->reverses_transaction_id = $reversesId;
        $newEntry->adjusts_transaction_id = $adjustsId;
        $newEntry->payload_type = $this->getPayloadType($payload);
        $newEntry->ledger_type = $this->getLedgerType();
        $newEntry->ledger_id = $this->getLedgerId($payload);
        $newEntry->effective_at = $effectiveAt;
        $newEntry->payload = $payload->jsonSerialize();
        $newEntry->reason = $reason;
        $newEntry->save();

        return $newEntry;
    }

    public function void(string $id, string $reason, ?CarbonImmutable $effectiveAt = null): LedgerTransaction
    {
        $transactionToReverse = LedgerTransaction::query()->findSole($id);

        return DB::connection()->transaction(fn () => $this->performPost(
            $this->computeOpposing(
                $this->deserialize(
                    $transactionToReverse->payload_type,
                    $transactionToReverse->payload,
                ),
            ),
            $reason,
            $effectiveAt ? $effectiveAt : $transactionToReverse->effective_at,
            reversesId: $id,
        ));
    }
}
