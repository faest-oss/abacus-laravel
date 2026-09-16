<?php

declare(strict_types=1);

namespace Faest\Abacus\Data;

final readonly class Transaction
{
    public function __construct(
        public string $id,
        public int $version,
        public string $operationId,
        public int $operationPosition,
    ) {
        //
    }
}
