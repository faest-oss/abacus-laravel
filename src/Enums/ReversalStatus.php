<?php

declare(strict_types=1);

namespace Faest\Abacus\Enums;

enum ReversalStatus: string
{
    case Reversed = 'reversed';
    case Unreversed = 'unreversed';
}
