<?php

declare(strict_types=1);

namespace Faest\Abacus\Enums;

enum CorrectionRelationship: string
{
    case Reversal = 'reversal';
    case Replacement = 'replacement';
    case Adjustment = 'adjustment';
}
