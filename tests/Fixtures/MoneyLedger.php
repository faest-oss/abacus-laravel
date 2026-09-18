<?php

declare(strict_types=1);

namespace Faest\Abacus\Tests\Fixtures;

use Faest\Abacus\Contracts\Ledger;
use Faest\Abacus\Contracts\LedgerPayload;
use InvalidArgumentException;
use JsonSerializable;

final class MoneyLedger implements Ledger
{
    public function getLedgerType(): string
    {
        return 'money-account';
    }

    /** @return array{total: int} */
    public function initializeAggregate(): array
    {
        return ['total' => 0];
    }

    /** @param array{total: int}|JsonSerializable $existingAggregate */
    public function applyToAggregate(LedgerPayload $payload, array|JsonSerializable $existingAggregate): array
    {
        if (! $payload instanceof MoneyPayload || ! is_array($existingAggregate)) {
            throw new InvalidArgumentException('Unsupported payload.');
        }

        return ['total' => $existingAggregate['total'] + $payload->amount()];
    }

    public function assertValidPayload(LedgerPayload $payload): void
    {
        if (! $payload instanceof MoneyPayload) {
            throw new InvalidArgumentException('Unsupported payload.');
        }
    }

    public function assertAggregateInvariants(array|JsonSerializable $aggregate): void {}

    public function computeOpposing(LedgerPayload $payload): LedgerPayload
    {
        if (! $payload instanceof MoneyPayload) {
            throw new InvalidArgumentException('Unsupported payload.');
        }

        return new MoneyPayload(-$payload->amount(), $payload->currency());
    }
}
