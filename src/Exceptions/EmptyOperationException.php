<?php

declare(strict_types=1);

namespace Faest\Abacus\Exceptions;

use InvalidArgumentException;

final class EmptyOperationException extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('A ledger operation must contain at least one action.');
    }
}
