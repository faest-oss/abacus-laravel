<?php

declare(strict_types=1);

namespace Faest\Abacus\Data;

final readonly class LedgerReplacementResult
{
    public function __construct(
        public string $operationId,
        public Transaction $reversalTransaction,
        public Transaction $replacementTransaction,
    ) {}
}
