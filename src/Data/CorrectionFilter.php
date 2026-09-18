<?php

declare(strict_types=1);

namespace Faest\Abacus\Data;

use Faest\Abacus\Enums\CorrectionRelationship;
use InvalidArgumentException;

final readonly class CorrectionFilter
{
    public function __construct(
        public CorrectionRelationship $relationship,
        public ?string $targetTransactionId = null,
    ) {
        if ($this->targetTransactionId === '') {
            throw new InvalidArgumentException('Correction target transaction ID must not be empty.');
        }
    }
}
