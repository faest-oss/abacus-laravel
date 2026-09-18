<?php

declare(strict_types=1);

namespace Faest\Abacus\Contracts;

use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Models\LedgerTransaction;
use Illuminate\Database\Connection;

interface Projector
{
    public function project(
        LedgerTransaction $transaction,
        LedgerPayload $payload,
        PostingContext $context,
        Connection $connection,
    ): void;
}
