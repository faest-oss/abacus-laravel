<?php

declare(strict_types=1);

namespace Faest\Abacus\Contracts;

use Faest\Abacus\Contracts\LedgerPayload;

interface DeserializablePayload extends LedgerPayload
{
    /**
     * Deserialize json into typed payload instance.
     * @param array<string, mixed> $data
     */
    public static function fromPayload(array $data): static;
}
