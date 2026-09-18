<?php

declare(strict_types=1);

namespace Faest\Abacus\Tests\Fixtures;

use Faest\Abacus\Contracts\Ledger;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Contracts\SnapshotsAggregate;
use Faest\Abacus\Data\GenericPayload;
use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;

final class SnapshotLedger implements Ledger, SnapshotsAggregate
{
    public function __construct(
        private int $formatVersion = 1,
        private bool $failSerialization = false,
        private bool $failHydration = false,
    ) {}

    public function getLedgerType(): string
    {
        return 'snapshot-account';
    }

    /** @return array{total: int} */
    public function initializeAggregate(): array
    {
        return ['total' => 0];
    }

    /** @param array{total: int}|JsonSerializable $existingAggregate */
    public function applyToAggregate(LedgerPayload $payload, array|JsonSerializable $existingAggregate): array
    {
        if (! $payload instanceof GenericPayload || ! is_array($existingAggregate)) {
            throw new InvalidArgumentException('Unsupported snapshot fixture value.');
        }

        return ['total' => $existingAggregate['total'] + $payload->payload()['amount']];
    }

    public function assertValidPayload(LedgerPayload $payload): void
    {
        if (! $payload instanceof GenericPayload) {
            throw new InvalidArgumentException('Unsupported snapshot fixture payload.');
        }
    }

    public function assertAggregateInvariants(array|JsonSerializable $aggregate): void {}

    public function computeOpposing(LedgerPayload $payload): LedgerPayload
    {
        if (! $payload instanceof GenericPayload) {
            throw new InvalidArgumentException('Unsupported snapshot fixture payload.');
        }

        return GenericPayload::make($payload->payloadType(), [
            ...$payload->payload(),
            'amount' => -$payload->payload()['amount'],
        ]);
    }

    public function snapshotVersion(): int
    {
        return $this->formatVersion;
    }

    public function serializeAggregateSnapshot(array|JsonSerializable $aggregate): array
    {
        if ($this->failSerialization) {
            throw new RuntimeException('Snapshot serialization failed.');
        }

        if (! is_array($aggregate)) {
            throw new InvalidArgumentException('Unsupported snapshot fixture aggregate.');
        }

        return $aggregate;
    }

    public function hydrateAggregateSnapshot(array $snapshot): array|JsonSerializable
    {
        if ($this->failHydration) {
            throw new RuntimeException('Snapshot hydration failed.');
        }

        return $snapshot;
    }
}
