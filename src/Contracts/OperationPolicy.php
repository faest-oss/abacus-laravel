<?php

declare(strict_types=1);

namespace Faest\Abacus\Contracts;

use Faest\Abacus\Data\OperationValidationContext;

interface OperationPolicy
{
    public function assertOperationAllowed(OperationValidationContext $context): void;
}
