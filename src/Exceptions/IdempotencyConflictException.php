<?php

declare(strict_types=1);

namespace Faest\Abacus\Exceptions;

use Exception;

// use Illuminate\Http\Request;
// use Illuminate\Http\Response;

class IdempotencyConflictException extends Exception
{
    public function __construct(
        public readonly string $ledgerType,
        public readonly string $idempotencyKey,
        string $message = '',
    ) {
        parent::__construct(
            $message ?: "Idempotency key '{$idempotencyKey}' was already used with different payload or stream target on ledger '{$ledgerType}'."
        );
    }
}
