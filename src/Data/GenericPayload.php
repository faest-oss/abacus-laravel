<?php

declare(strict_types=1);

namespace Faest\Abacus\Data;

use Faest\Abacus\Contracts\LedgerPayload;

final class GenericPayload implements LedgerPayload
{
    /**
     * @param  array<mixed>  $payload
     */
    public function __construct(
        private string $payloadType,
        private array $payload,
    ) {}

    /**
     * @param  array<mixed>  $payload
     */
    public static function make(string $payloadType, array $payload): static
    {
        return new self($payloadType, $payload);
    }

    public function payloadType(): string
    {
        return $this->payloadType;
    }

    /**
     * @return array<mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function jsonSerialize(): mixed
    {
        return $this->payload;
    }
}
