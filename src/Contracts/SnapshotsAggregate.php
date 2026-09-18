<?php

declare(strict_types=1);

namespace Faest\Abacus\Contracts;

use JsonSerializable;

interface SnapshotsAggregate
{
    public function snapshotVersion(): int;

    /**
     * @param  array<mixed>|JsonSerializable  $aggregate
     * @return array<mixed>
     */
    public function serializeAggregateSnapshot(array|JsonSerializable $aggregate): array;

    /**
     * @param  array<mixed>  $snapshot
     * @return array<mixed>|JsonSerializable
     */
    public function hydrateAggregateSnapshot(array $snapshot): array|JsonSerializable;
}
