<?php

declare(strict_types=1);

namespace Faest\Abacus\Tests\Fixtures;

use DomainException;
use Faest\Abacus\Contracts\CorrectionPolicy;
use Faest\Abacus\Contracts\Ledger;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Data\CorrectionContext;
use Faest\Abacus\Data\GenericPayload;
use Faest\Abacus\Enums\OperationKind;
use JsonSerializable;

final class RestrictedCorrectionLedger implements CorrectionPolicy, Ledger
{
    /** @return array{total: int} */
    public function initializeAggregate(): array
    {
        return ['total' => 0];
    }

    /**
     * @param  array<mixed>|JsonSerializable  $existingAggregate
     * @return array{total: int}
     */
    public function applyToAggregate(LedgerPayload $payload, array|JsonSerializable $existingAggregate): array
    {
        if (! $payload instanceof GenericPayload || ! is_array($existingAggregate)) {
            throw new DomainException('Unsupported payload.');
        }

        return ['total' => $existingAggregate['total'] + $payload->payload()['amount']];
    }

    public function assertValidPayload(LedgerPayload $payload): void
    {
        if (! $payload instanceof GenericPayload) {
            throw new DomainException('Unsupported payload.');
        }
    }

    public function assertAggregateInvariants(array|JsonSerializable $aggregate): void
    {
        // This fixture restricts corrections rather than balances.
    }

    public function getLedgerType(): string
    {
        return 'restricted-account';
    }

    public function computeOpposing(LedgerPayload $payload): LedgerPayload
    {
        if (! $payload instanceof GenericPayload) {
            throw new DomainException('Unsupported payload.');
        }

        return GenericPayload::make($payload->payloadType(), [
            'amount' => -$payload->payload()['amount'],
        ]);
    }

    /** @param array<mixed> $payload */
    public function deserialize(string $eventType, array $payload): LedgerPayload
    {
        return GenericPayload::make($eventType, $payload);
    }

    public function assertCorrectionAllowed(CorrectionContext $context): void
    {
        if ($context->kind === OperationKind::Adjustment) {
            throw new DomainException('Adjustments are not permitted.');
        }
    }
}
