<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
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
    Abacus::registerLedger(new MoneyLedger);

    $original = Abacus::post(
        'money-account',
        'one',
        new MoneyPayload(100, 'USD'),
        typedPayloadContext(),
    );

    expect(Abacus::getAggregate('money-account', 'one'))->toBe(['balance_minor' => 100]);

    Abacus::reverse($original->id, typedPayloadContext());

    expect(Abacus::getAggregate('money-account', 'one'))->toBe(['balance_minor' => 0]);
});

it('registers configured ledgers and their declared payload types', function () {
    config()->set('abacus.ledgers', [MoneyLedger::class]);

    $abacus = app(AbacusCoordinator::class);
    $transaction = $abacus->post(
        'money-account',
        'configured',
        new MoneyPayload(25, 'USD'),
        typedPayloadContext(),
    );

    expect($transaction->version)->toBe(1)
        ->and($abacus->getAggregate('money-account', 'configured'))
        ->toBe(['balance_minor' => 25]);
});

it('validates every affected accounting boundary inside an operation policy', function () {
    Abacus::registerLedger(new MoneyLedger(allowNegative: false));

    Abacus::post('money-account', 'one', new MoneyPayload(100, 'USD'), temporalMoneyContext('2026-01-01'));
    Abacus::post('money-account', 'one', new MoneyPayload(-90, 'USD'), temporalMoneyContext('2026-03-01'));
    Abacus::post('money-account', 'one', new MoneyPayload(90, 'USD'), temporalMoneyContext('2026-04-01'));

    expect(fn () => Abacus::post(
        'money-account',
        'one',
        new MoneyPayload(-50, 'USD'),
        temporalMoneyContext('2026-01-15'),
    ))->toThrow(InvalidArgumentException::class, 'cannot have a negative balance');

    assertDatabaseCount('ledger_operation', 3);
    assertDatabaseCount('ledger_transaction', 3);
});

it('rejects malformed money currencies without writing an operation', function (string $currency) {
    Abacus::registerLedger(new MoneyLedger);

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

function temporalMoneyContext(string $accountingDate): PostingContext
{
    return PostingContext::forProcess(
        'typed-payload-test',
        CarbonImmutable::parse($accountingDate),
        CarbonImmutable::parse($accountingDate),
        'test temporal money policy',
    );
}
