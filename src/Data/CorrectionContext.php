<?php

declare(strict_types=1);

namespace Faest\Abacus\Data;

use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Enums\OperationKind;
use Faest\Abacus\Models\LedgerTransaction;
use JsonSerializable;

final readonly class CorrectionContext
{
    /**
     * @param  list<LedgerPayload>  $proposedPayloads
     * @param  array<mixed>|JsonSerializable  $aggregateBefore
     * @param  array<mixed>|JsonSerializable  $aggregateAfter
     */
    public function __construct(
        public OperationKind $kind,
        public LedgerTransaction $original,
        public array $proposedPayloads,
        public PostingContext $postingContext,
        public array|JsonSerializable $aggregateBefore,
        public array|JsonSerializable $aggregateAfter,
    ) {}
}
