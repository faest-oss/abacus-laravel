<?php

declare(strict_types=1);

namespace Faest\Abacus\Contracts;

use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Models\LedgerOperation;
use Faest\Abacus\Models\LedgerTransaction;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection;

interface OperationProjector
{
    /** @param Collection<int, LedgerTransaction> $transactions */
    public function projectOperation(
        LedgerOperation $operation,
        Collection $transactions,
        PostingContext $context,
        Connection $connection,
    ): void;
}
