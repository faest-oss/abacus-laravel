<?php

declare(strict_types=1);

namespace Faest\Abacus\Contracts;

use JsonSerializable;

interface Ledger
{
    public function getLedgerType(): string;

    /**
     * The blank initial state of an empty stream.
     *
     * @return array<mixed>|JsonSerializable
     */
    public function initializeAggregate(): array|JsonSerializable;

    /**
     * Pure reducer: apply event to existing state -> return new state.
     *
     * @param  array<mixed>|JsonSerializable  $existingAggregate
     * @return array<mixed>|JsonSerializable
     */
    public function applyToAggregate(LedgerPayload $payload, array|JsonSerializable $existingAggregate): array|JsonSerializable;

    /**
     * Invariant check: throw FailedInvariantException if business rule is violated.
     *
     * @param  array<mixed>|JsonSerializable  $aggregate
     */
    public function assertInvariants(LedgerPayload $payload, array|JsonSerializable $aggregate): void;

    /**
     * Compute the opposing payload for full reversals.
     */
    public function computeOpposing(LedgerPayload $payload): LedgerPayload;

    /**
     * Deserialize raw database JSON into a typed payload object.
     *
     * @param  array<mixed>  $payload
     */
    public function deserialize(string $eventType, array $payload): LedgerPayload;
}
