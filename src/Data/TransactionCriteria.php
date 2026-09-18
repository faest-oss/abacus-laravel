<?php

declare(strict_types=1);

namespace Faest\Abacus\Data;

use Faest\Abacus\Enums\ReversalStatus;
use InvalidArgumentException;

final readonly class TransactionCriteria
{
    public function __construct(
        public TemporalView $view = new TemporalView,
        public ?string $operationId = null,
        public ?string $correlationId = null,
        public ?CorrectionFilter $correction = null,
        public ?ReversalStatus $reversalStatus = null,
    ) {
        if ($this->operationId === '') {
            throw new InvalidArgumentException('Operation ID must not be empty.');
        }

        if ($this->correlationId === '') {
            throw new InvalidArgumentException('Correlation ID must not be empty.');
        }
    }
}
