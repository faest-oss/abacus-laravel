<?php

declare(strict_types=1);

namespace Faest\Abacus\Exceptions;

use Exception;

// use Illuminate\Http\Request;
// use Illuminate\Http\Response;

class LedgerImmutableException extends Exception
{
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
