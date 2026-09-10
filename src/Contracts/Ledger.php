<?php

declare(strict_types=1);

namespace Faest\Abacus\Contracts;

use JsonSerializable;

/**
 * @template TAggregate of array|JsonSerializable|object
 * @template TPayload of LedgerPayload
 */
interface Ledger
{
    public static function getLedgerType(): string;

    /**
     * The blank initial state of an empty stream.
     * @return TAggregate
     */
    public function initializeAggregate(): mixed;

    /**
     * Pure reducer: apply event to existing state -> return new state.
     * @param TPayload $payload
     * @param TAggregate $existingAggregate
     * @return TAggregate
     */
    public function applyToAggregate(LedgerPayload $payload, mixed $existingAggregate): mixed;

    /**
     * Invariant check: throw FailedInvariantException if business rule is violated.
     * @param TPayload $payload
     * @param TAggregate $aggregate
     */
    public function assertInvariants(LedgerPayload $payload, mixed $aggregate): void;

    /**
     * Compute the opposing payload for full reversals.
     * @param TPayload $payload
     * @return TPayload
     */
    public function computeOpposing(LedgerPayload $payload): LedgerPayload;

    /**
     * Deserialize raw database JSON into a typed payload object.
     * @param array<mixed> $payload
     */
    public function deserialize(string $eventType, array $payload): LedgerPayload;
}
