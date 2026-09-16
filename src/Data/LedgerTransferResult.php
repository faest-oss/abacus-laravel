<?php

declare(strict_types=1);

namespace Faest\Abacus\Data;

final readonly class LedgerTransferResult
{
    public function __construct(
        public string $operationId,
        public string $correlationId,
        public Transaction $sourceTransaction,
        public Transaction $destinationTransaction,
    ) {}
}
