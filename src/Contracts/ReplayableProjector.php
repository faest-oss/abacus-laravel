<?php

declare(strict_types=1);

namespace Faest\Abacus\Contracts;

use Faest\Abacus\Models\LedgerOperation;
use Faest\Abacus\Models\LedgerTransaction;
use Illuminate\Database\Connection;

interface ReplayableProjector
{
    public function resetProjection(string $ledgerType, Connection $connection): void;

    public function projectHistorical(
        LedgerTransaction $transaction,
        LedgerPayload $payload,
        LedgerOperation $operation,
        Connection $connection,
    ): void;
}
