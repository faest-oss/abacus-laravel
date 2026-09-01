<?php

declare(strict_types=1);

namespace Faest\Abacus\Tests\Fixtures;

use Exception;
use Faest\Abacus\AbstractLedger;
use JsonSerializable;

final class SimpleLedger extends AbstractLedger
{
    /**
     * @return array<mixed>
     */
    public function initializeAggregate(): array
    {
        return ['total' => 0];
    }

    /**
     * @param  array<mixed>|JsonSerializable  $entry
     * @param  array<mixed>|JsonSerializable  $existingAggregate
     * @return array<mixed>
     */
    public function applyToAggregate(
        array|JsonSerializable $entry,
        array|JsonSerializable $existingAggregate,
    ): array {
        if (! is_array($entry) || ! is_array($existingAggregate)) {
            throw new Exception('must be arrays');
        }

        $existingAggregate['total'] += $entry['amount'];

        return $existingAggregate;
    }

    /**
     * @param  array<mixed>|JsonSerializable  $entry
     * @param  array<mixed>|JsonSerializable  $aggregate
     */
    public function assertInvariants(
        array|JsonSerializable $entry,
        array|JsonSerializable $aggregate,
    ): void {
        if (! is_array($entry) || ! is_array($aggregate)) {
            throw new Exception('must be arrays');
        }

        if ($aggregate['total'] + $entry['amount'] < 0) {
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
        assert(is_array($payload));

        return $payload['account_id'];
    }

    /**
     * @param  array<mixed>|JsonSerializable  $payload
     */
    public function getPayloadType(array|JsonSerializable $payload): string
    {
        assert(is_array($payload));

        return $payload['type'];
    }

    /**
     * @param  array<mixed>|JsonSerializable  $entry
     * @return array<mixed>
     */
    public function computeOpposing(array|JsonSerializable $entry): array
    {
        assert(is_array($entry));
        $entry['amount'] = -1 * $entry['amount'];

        return $entry;
    }
}
