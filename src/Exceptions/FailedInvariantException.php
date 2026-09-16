<?php

declare(strict_types=1);

namespace Faest\Abacus\Exceptions;

use Exception;
use Throwable;

// use Illuminate\Http\Request;
// use Illuminate\Http\Response;

class FailedInvariantException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $ledgerType,
        public readonly string $ledgerId,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Report the exception.
     */
    public function report(): void
    {
        //
    }

    /**
     * Render the exception as an HTTP response.
     */
    // public function render(Request $request): Response
    // {
    //     //
    // }
}
