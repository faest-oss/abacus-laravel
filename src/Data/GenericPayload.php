<?php

declare(strict_types=1);

namespace Faest\Abacus\Data;

use Faest\Abacus\Contracts\LedgerPayload;

final class GenericPayload implements LedgerPayload
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private string $payloadType,
        private array $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
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
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function jsonSerialize(): array
    {
        return $this->payload;
    }
}
