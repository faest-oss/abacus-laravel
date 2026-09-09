<?php

declare(strict_types=1);

namespace Faest\Abacus\Data;

readonly class VoidDraft
{
    public function __construct(
        public string $transactionId,
        public ?int $expectedVersion = null,
    ) {
        //
    }

    public static function make(string $transactionId): self
    {
        return new self(
            transactionId: $transactionId,
        );
    }

    public function failIfVersionMismatch(int $expectedVersion): self
    {
        return new self(
            transactionId: $this->transactionId,
            expectedVersion: $expectedVersion,
        );
    }
}
