<?php

declare(strict_types=1);

namespace Faest\Abacus\Data;

use Carbon\CarbonImmutable;
use Faest\Abacus\Contracts\LedgerPayload;

final readonly class AccountingEntry
{
    public function __construct(
        public CarbonImmutable $accountingDate,
        public LedgerPayload $payload,
        public int $streamVersion,
        public bool $proposed,
    ) {}
}
