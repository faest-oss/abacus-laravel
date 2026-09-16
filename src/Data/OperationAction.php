<?php

declare(strict_types=1);

namespace Faest\Abacus\Data;

use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Enums\OperationKind;

/** @internal */
final readonly class OperationAction
{
    public function __construct(
        public OperationKind $kind,
        public ?string $ledgerType = null,
        public ?string $ledgerId = null,
        public ?LedgerPayload $payload = null,
        public ?string $targetTransactionId = null,
        public ?string $destinationLedgerId = null,
        public ?int $expectedVersion = null,
        public ?int $expectedDestinationVersion = null,
    ) {}

    public static function post(string $ledgerType, string $ledgerId, LedgerPayload $payload, ?int $expectedVersion = null): self
    {
        return new self(OperationKind::Posting, $ledgerType, $ledgerId, $payload, expectedVersion: $expectedVersion);
    }

    public static function reverse(string $transactionId, ?int $expectedVersion = null): self
    {
        return new self(OperationKind::Reversal, targetTransactionId: $transactionId, expectedVersion: $expectedVersion);
    }

    public static function replace(string $transactionId, LedgerPayload $replacement, ?int $expectedVersion = null): self
    {
        return new self(OperationKind::Replacement, payload: $replacement, targetTransactionId: $transactionId, expectedVersion: $expectedVersion);
    }

    public static function adjust(string $transactionId, LedgerPayload $delta, ?int $expectedVersion = null): self
    {
        return new self(OperationKind::Adjustment, payload: $delta, targetTransactionId: $transactionId, expectedVersion: $expectedVersion);
    }

    public static function transfer(
        string $ledgerType,
        string $sourceLedgerId,
        string $destinationLedgerId,
        LedgerPayload $payload,
        ?int $expectedSourceVersion = null,
        ?int $expectedDestinationVersion = null,
    ): self {
        return new self(
            OperationKind::Transfer,
            $ledgerType,
            $sourceLedgerId,
            $payload,
            destinationLedgerId: $destinationLedgerId,
            expectedVersion: $expectedSourceVersion,
            expectedDestinationVersion: $expectedDestinationVersion,
        );
    }
}
