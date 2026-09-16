<?php

declare(strict_types=1);

namespace Faest\Abacus\Exceptions;

use DomainException;

final class InvalidCorrectionException extends DomainException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $transactionId,
        string $message,
    ) {
        parent::__construct($message);
    }
}
