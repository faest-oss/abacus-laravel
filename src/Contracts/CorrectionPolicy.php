<?php

declare(strict_types=1);

namespace Faest\Abacus\Contracts;

use Faest\Abacus\Data\CorrectionContext;

interface CorrectionPolicy
{
    public function assertCorrectionAllowed(CorrectionContext $context): void;
}
