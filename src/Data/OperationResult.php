<?php

declare(strict_types=1);

namespace Faest\Abacus\Data;

use Faest\Abacus\Enums\OperationKind;

final readonly class OperationResult
{
    /** @param list<Transaction> $transactions */
    public function __construct(
        public string $id,
        public OperationKind $kind,
        public array $transactions,
    ) {}
}
