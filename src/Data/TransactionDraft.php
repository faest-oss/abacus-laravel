<?php

declare(strict_types=1);

namespace Faest\Abacus\Data;

use Faest\Abacus\Contracts\LedgerPayload;

readonly class TransactionDraft
{
    public function __construct(
        public LedgerPayload $payload,
        public string $ledgerId,
        public ?string $ledgerType = null,
        public ?int $expectedVersion = null,
    ) {
        //
    }

    public static function make(string $ledgerType, string $ledgerId, LedgerPayload $payload): self
    {
        return new self(
            payload: $payload,
            ledgerId: $ledgerId,
            ledgerType: $ledgerType,
        );
    }

    public function failIfVersionIsnt(?int $expectedVersion): self
    {
        return new self(
            payload: $this->payload,
            ledgerId: $this->ledgerId,
            ledgerType: $this->ledgerType,
            expectedVersion: $expectedVersion,
        );
    }
}
