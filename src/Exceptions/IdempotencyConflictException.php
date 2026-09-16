<?php

declare(strict_types=1);

namespace Faest\Abacus\Exceptions;

use Exception;

// use Illuminate\Http\Request;
// use Illuminate\Http\Response;

class IdempotencyConflictException extends Exception
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $existingOperationId,
        string $message = '',
    ) {
        parent::__construct(
            $message ?: "Idempotency key '{$idempotencyKey}' was already used by operation '{$existingOperationId}' with different intent.",
        );
    }
}
