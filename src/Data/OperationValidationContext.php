<?php

declare(strict_types=1);

namespace Faest\Abacus\Data;

use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Enums\OperationKind;
use JsonSerializable;

final readonly class OperationValidationContext
{
    /**
     * @param  list<LedgerPayload>  $proposedPayloads
     * @param  list<AccountingEntry>  $accountingEntries
     * @param  array<mixed>|JsonSerializable  $aggregateBefore
     * @param  array<mixed>|JsonSerializable  $aggregateAfter
     */
    public function __construct(
        public OperationKind $kind,
        public string $ledgerType,
        public string $ledgerId,
        public int $streamVersionBefore,
        public array $proposedPayloads,
        public PostingContext $postingContext,
        public array|JsonSerializable $aggregateBefore,
        public array|JsonSerializable $aggregateAfter,
        public array $accountingEntries,
    ) {}
}
