<?php

declare(strict_types=1);

namespace Faest\Abacus\Tests\Fixtures;

use Exception;
use Faest\Abacus\Contracts\Ledger;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Data\GenericPayload;
use JsonSerializable;

final class SimpleLedger implements Ledger
{
    /**
     * @return array{total: int}
     */
    public function initializeAggregate(): array
    {
        return ['total' => 0];
    }

    /**
     * @param  array<mixed>|JsonSerializable  $existingAggregate
     * @return array<mixed>
     */
    public function applyToAggregate(
        LedgerPayload $payload,
        array|JsonSerializable $existingAggregate,
    ): array {
        if (! $payload instanceof GenericPayload || ! is_array($existingAggregate)) {
            throw new Exception('must be arrays');
        }

        $existingAggregate['total'] += $payload->payload()['amount'];

        return $existingAggregate;
    }

    public function assertValidPayload(LedgerPayload $payload): void
    {
        if (! $payload instanceof GenericPayload) {
            throw new Exception('must be arrays');
        }
    }

    /** @param array<mixed>|JsonSerializable $aggregate */
    public function assertAggregateInvariants(array|JsonSerializable $aggregate): void
    {
        if (! is_array($aggregate)) {
            throw new Exception('must be arrays');
        }

        if ($aggregate['total'] < 0) {
            throw new Exception('Cannot be negative');
        }
    }

    public function getLedgerType(): string
    {
        return 'cash-account';
    }

    /**
     * @param  array<mixed>|JsonSerializable  $payload
     */
    public function getLedgerId(array|JsonSerializable $payload): string
    {
        assert($payload instanceof GenericPayload);

        return $payload->payload()['account_id'];
    }

    public function getPayloadType(LedgerPayload $payload): string
    {
        assert($payload instanceof GenericPayload);

        return $payload->payloadType();
    }

    public function computeOpposing(LedgerPayload $payload): LedgerPayload
    {
        assert($payload instanceof GenericPayload);
        $newPayload = $payload->payload();
        $newPayload['amount'] = -1 * $newPayload['amount'];

        return GenericPayload::make($payload->payloadType(), $newPayload);
    }

    /**
     * @param  array<mixed>  $payload
     */
    public function deserialize(string $eventType, array $payload): LedgerPayload
    {
        return GenericPayload::make($eventType, $payload);
    }
}
