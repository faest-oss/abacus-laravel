<?php

declare(strict_types=1);

namespace Faest\Abacus\Enums;

enum OperationKind: string
{
    case Posting = 'posting';
    case Reversal = 'reversal';
    case Replacement = 'replacement';
    case Adjustment = 'adjustment';
    case Transfer = 'transfer';
    case OperationReversal = 'operation_reversal';
    case Composite = 'composite';
}
