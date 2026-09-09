<?php

declare(strict_types=1);

namespace Faest\Abacus\Exceptions;

use Exception;
use Faest\Abacus\Data\Append;
use Faest\Abacus\Data\TransactionDraft;
use Throwable;

// use Illuminate\Http\Request;
// use Illuminate\Http\Response;

class FailedInvariantException extends Exception
{
    public function __construct(
        string $message,
        private Append $failedAppend,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getFailedDraft(): TransactionDraft
    {
        return $this->failedDraft;
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
