<?php

declare(strict_types=1);

namespace Faest\Abacus\Contracts;

interface HasPayloadTypes
{
    /** @return array<string, class-string<DeserializablePayload>> */
    public function payloadTypes(): array;
}
