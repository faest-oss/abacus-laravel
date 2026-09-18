<?php

declare(strict_types=1);

use Faest\Abacus\Abacus as AbacusCoordinator;
use Faest\Abacus\Data\GenericPayload;
use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Exceptions\FailedInvariantException;
use Faest\Abacus\Facades\Abacus;
use Faest\Abacus\Models\LedgerTransaction;
use Faest\Abacus\PayloadRegistry;
use Faest\Abacus\Tests\Fixtures\MoneyLedger;
use Faest\Abacus\Tests\Fixtures\MoneyPayload;
use Faest\Abacus\Tests\Fixtures\SimpleLedger;

use function Pest\Laravel\assertDatabaseCount;

it('loads configured payload mappings into the registry shared by Abacus', function () {
    config()->set('abacus.payloads', ['test:money' => MoneyPayload::class]);

    $registry = app(PayloadRegistry::class);
    $abacus = app(AbacusCoordinator::class);
    $payload = $abacus->deserializePayload('test:money', ['amount' => 10, 'currency' => 'USD']);

    expect($payload)->toBeInstanceOf(MoneyPayload::class)
        ->and($registry->deserialize('test:money', ['amount' => 20, 'currency' => 'USD']))
        ->toBeInstanceOf(MoneyPayload::class);
});

it('uses the central registry for aggregate reads and corrections', function () {
    Abacus::registerLedger(new MoneyLedger)
        ->registerPayload('test:money', MoneyPayload::class);

    $original = Abacus::post(
        'money-account',
        'one',
        new MoneyPayload(100, 'USD'),
        typedPayloadContext(),
    );

    expect(Abacus::getAggregate('money-account', 'one'))->toBe(['total' => 100]);

    Abacus::reverse($original->id, typedPayloadContext());

    expect(Abacus::getAggregate('money-account', 'one'))->toBe(['total' => 0]);
});

it('rejects malformed money currencies without writing an operation', function (string $currency) {
    Abacus::registerLedger(new MoneyLedger)
        ->registerPayload('test:money', MoneyPayload::class);

    expect(fn () => Abacus::post(
        'money-account',
        'one',
        new MoneyPayload(100, $currency),
        typedPayloadContext(),
    ))->toThrow(FailedInvariantException::class, 'three uppercase ASCII letters');

    assertDatabaseCount('ledger_operation', 0);
    assertDatabaseCount('ledger_transaction', 0);
})->with(['usd', 'US', 'USDX', '']);

it('persists canonical payload arrays and fingerprints equivalent key orders identically', function () {
    Abacus::registerLedger(new SimpleLedger);
    $context = typedPayloadContext()->withIdempotencyKey('canonical-payload');
    $firstPayload = GenericPayload::make('entry', [
        'nested' => ['z' => 2, 'a' => 1],
        'amount' => 100.0,
    ]);
    $reorderedPayload = GenericPayload::make('entry', [
        'amount' => 100.0,
        'nested' => ['a' => 1, 'z' => 2],
    ]);

    $first = Abacus::post('cash-account', 'one', $firstPayload, $context);
    $replayed = Abacus::post('cash-account', 'one', $reorderedPayload, $context);
    $stored = LedgerTransaction::query()->sole()->payload;

    expect($replayed)->toEqual($first)
        ->and(array_keys($stored))->toBe(['amount', 'nested'])
        ->and(array_keys($stored['nested']))->toBe(['a', 'z'])
        ->and($stored['amount'])->toBe(100.0);
});

it('rejects unsupported nested payload values before persistence', function () {
    Abacus::registerLedger(new SimpleLedger);

    expect(fn () => Abacus::post(
        'cash-account',
        'one',
        GenericPayload::make('entry', ['amount' => 1, 'unsupported' => new stdClass]),
        typedPayloadContext(),
    ))->toThrow(InvalidArgumentException::class, 'JSON scalar');

    assertDatabaseCount('ledger_operation', 0);
});

function typedPayloadContext(): PostingContext
{
    return PostingContext::forProcess('typed-payload-test', now(), now(), 'test typed payloads');
}
