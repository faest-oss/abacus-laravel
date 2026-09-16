<?php

declare(strict_types=1);

namespace Faest\Abacus\Data;

use Faest\Abacus\Contracts\LedgerPayload;

/** @internal */
final readonly class Append
{
    public function __construct(
        public string $ledgerType,
        public string $ledgerId,
        public LedgerPayload $payload,
        public ?int $expectedVersion = null,
        public ?string $reversesId = null,
        public ?string $replacesId = null,
        public ?string $adjustsId = null,
        public ?int $version = null,
        public ?int $operationPosition = null,
    ) {}

    public function planned(int $version, int $operationPosition): self
    {
        return new self(
            $this->ledgerType,
            $this->ledgerId,
            $this->payload,
            $this->expectedVersion,
            $this->reversesId,
            $this->replacesId,
            $this->adjustsId,
            $version,
            $operationPosition,
        );
    }
}
