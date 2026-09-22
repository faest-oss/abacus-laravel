<?php

declare(strict_types=1);

namespace Faest\Abacus\Contracts;

interface OpposablePayload extends LedgerPayload
{
    public function opposing(): LedgerPayload;
}
