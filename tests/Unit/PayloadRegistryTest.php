<?php

declare(strict_types=1);

use Faest\Abacus\Contracts\DeserializablePayload;
use Faest\Abacus\Data\GenericPayload;
use Faest\Abacus\PayloadRegistry;
use Faest\Abacus\Tests\Fixtures\MoneyPayload;

it('registers deserializable payloads idempotently and rejects conflicts', function () {
    $registry = new PayloadRegistry;

    expect($registry->register('test:money', MoneyPayload::class))
        ->toBe($registry)
        ->and($registry->register('test:money', MoneyPayload::class))
        ->toBe($registry)
        ->and(fn () => $registry->register('test:money', ConflictingPayload::class))
        ->toThrow(InvalidArgumentException::class, 'already registered')
        ->and(fn () => $registry->register('', MoneyPayload::class))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register('invalid', GenericPayload::class))
        ->toThrow(InvalidArgumentException::class);
});

it('hydrates registered payloads and preserves unknown payloads', function () {
    $registry = (new PayloadRegistry)->register('test:money', MoneyPayload::class);

    $typed = $registry->deserialize('test:money', ['amount' => 100, 'currency' => 'USD']);
    $unknown = $registry->deserialize('future:type', ['nested' => ['value' => 1]]);

    expect($typed)->toBeInstanceOf(MoneyPayload::class)
        ->and($typed->jsonSerialize())->toBe(['amount' => 100, 'currency' => 'USD'])
        ->and($unknown)->toBeInstanceOf(GenericPayload::class)
        ->and($unknown->payloadType())->toBe('future:type')
        ->and($unknown->jsonSerialize())->toBe(['nested' => ['value' => 1]]);
});

it('rejects coercible financial values during hydration', function (mixed $amount) {
    $registry = (new PayloadRegistry)->register('test:money', MoneyPayload::class);

    expect(fn () => $registry->deserialize('test:money', ['amount' => $amount, 'currency' => 'USD']))
        ->toThrow(InvalidArgumentException::class, 'Amounts must be integers.');
})->with([100.0, '100']);

final class ConflictingPayload implements DeserializablePayload
{
    public function payloadType(): string
    {
        return 'conflict';
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [];
    }

    /** @param array<string, mixed> $data */
    public static function fromPayload(array $data): static
    {
        return new self;
    }
}
