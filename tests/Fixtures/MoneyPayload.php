<?php

declare(strict_types=1);

namespace Faest\Abacus\Tests\Fixtures;

use Faest\Abacus\Contracts\DeserializablePayload;
use Faest\Abacus\Contracts\HasMoneyAmount;
use InvalidArgumentException;

final readonly class MoneyPayload implements DeserializablePayload, HasMoneyAmount
{
    public function __construct(
        private mixed $rawAmount,
        private mixed $rawCurrency,
    ) {
        if (! is_int($rawAmount)) {
            throw new InvalidArgumentException('Amounts must be integers.');
        }

        if (! is_string($rawCurrency)) {
            throw new InvalidArgumentException('Currencies must be strings.');
        }
    }

    public function payloadType(): string
    {
        return 'test:money';
    }

    public function amount(): int
    {
        return $this->rawAmount;
    }

    public function currency(): string
    {
        return $this->rawCurrency;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->rawAmount,
            'currency' => $this->rawCurrency,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromPayload(array $data): static
    {
        return new self($data['amount'] ?? null, $data['currency'] ?? null);
    }
}
