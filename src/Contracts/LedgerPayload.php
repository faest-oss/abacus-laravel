<?php

declare(strict_types=1);

namespace Faest\Abacus\Contracts;

use JsonSerializable;

interface LedgerPayload extends JsonSerializable
{
    public function payloadType(): string;
}
