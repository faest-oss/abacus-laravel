<?php

declare(strict_types=1);

namespace Faest\Abacus\Contracts;

interface DeserializablePayload extends LedgerPayload
{
    /**
     * Deserialize JSON into a typed payload instance.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromPayload(array $data): static;
}
