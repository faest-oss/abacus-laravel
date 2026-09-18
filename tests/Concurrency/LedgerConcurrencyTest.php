<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Faest\Abacus\Abacus;
use Faest\Abacus\Data\GenericPayload;
use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Exceptions\InvalidCorrectionException;
use Faest\Abacus\Exceptions\UnexpectedStreamVersionException;
use Faest\Abacus\Models\LedgerTransaction;
use Faest\Abacus\OperationBuilder;
use Faest\Abacus\PayloadRegistry;
use Faest\Abacus\Tests\Fixtures\SimpleLedger;
use Faest\Abacus\Tests\Fixtures\TwoProcessHarness;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    if (config('database.default') === 'sqlite') {
        // testing fine grained lock behavior on sqlite is meaningless. sqlite uses
        // coarse grained one writer per entire database lock behavior.
        return $this->markTestSkipped('Concurrency suite cannot run on sqlite');
    }

    $this->artisan('migrate:refresh');
    DB::connection()->table('ledger_transaction')->delete();
    DB::connection()->table('ledger_operation')->delete();
    DB::connection()->table('ledger_stream_head')->delete();
});

test('posts acquire an exclusive stream head lock', function () {
    DB::connection('pgsql')->table('ledger_stream_head')->insert([
        'ledger_type' => 'cash-account',
        'ledger_id' => '234',
        'version' => 0,
    ]);

    $firstAbacus = (new Abacus(new PayloadRegistry))->registerLedger(new SimpleLedger);
    $firstAbacus->overrideConnection('pgsql');

    $secondAbacus = (new Abacus(new PayloadRegistry))->registerLedger(new SimpleLedger);
    $secondAbacus->overrideConnection('pgsql2');
    $secondAbacus->overrideLockTimeout(0);

    $lockWasContended = false;

    DB::connection('pgsql')->listen(function ($query) use ($secondAbacus, &$lockWasContended) {
        if ($query->connectionName !== 'pgsql'
            || ! str_contains(strtolower($query->sql), 'for update')) {
            return;
        }

        try {
            $secondAbacus->post('cash-account', '234', stdPayload(), stdContext(), 0);
        } catch (QueryException $exception) {
            expect($exception->getCode())->toBe('55P03');
            $lockWasContended = true;
        }
    });

    $firstAbacus->post(
        'cash-account',
        '234',
        GenericPayload::make('deposit', ['amount' => 25]),
        stdContext(),
        0,
    );

    expect($lockWasContended)->toBeTrue();
    assertDatabaseCount('ledger_transaction', 1);
    assertDatabaseHas('ledger_stream_head', [
        'ledger_type' => 'cash-account',
        'ledger_id' => '234',
        'version' => 1,
    ]);
    expect(LedgerTransaction::query()->sole()->payload)->toBe(['amount' => 25]);
});

test('only one concurrent first post can expect version zero', function () {
    $context = stdContext();
    $post = static function () use ($context): array {
        $abacus = (new Abacus(new PayloadRegistry))->registerLedger(new SimpleLedger);

        try {
            $transaction = $abacus->post(
                'cash-account',
                '234',
                GenericPayload::make('deposit', ['amount' => 2]),
                $context,
                0,
            );

            return ['outcome' => 'committed', 'version' => $transaction->version];
        } catch (UnexpectedStreamVersionException $exception) {
            return ['outcome' => 'version-mismatch', 'version' => $exception->actualVersion];
        }
    };

    $results = TwoProcessHarness::run($post, $post);

    expect(array_column($results, 'outcome'))
        ->toContain('committed')
        ->toContain('version-mismatch')
        ->and(array_column($results, 'version'))->each->toBe(1);

    assertDatabaseCount('ledger_transaction', 1);
    assertDatabaseHas('ledger_stream_head', [
        'ledger_type' => 'cash-account',
        'ledger_id' => '234',
        'version' => 1,
    ]);
    expect(LedgerTransaction::query()->sole()->payload)->toBe(['amount' => 2]);
});

test('opposing operations acquire stream locks in the same order', function () {
    $postOperation = static function (array $ledgerIds, PostingContext $context): Closure {
        return static function () use ($ledgerIds, $context): array {
            $lockOrder = [];

            DB::listen(static function ($query) use (&$lockOrder) {
                if (str_contains(strtolower($query->sql), 'for update')) {
                    $lockOrder[] = (string) end($query->bindings);
                }
            });

            $operation = (new Abacus(new PayloadRegistry))->registerLedger(new SimpleLedger)->operation(
                $context,
                function (OperationBuilder $builder) use ($ledgerIds): void {
                    foreach ($ledgerIds as $ledgerId) {
                        $builder->post(
                            'cash-account',
                            $ledgerId,
                            GenericPayload::make('deposit', ['amount' => 2]),
                        );
                    }
                },
            );

            return [
                'lockOrder' => array_slice($lockOrder, 0, 2),
                'versions' => array_map(
                    static fn ($transaction): int => $transaction->version,
                    $operation->transactions,
                ),
            ];
        };
    };

    $context = stdContext();

    $results = TwoProcessHarness::run(
        $postOperation(['account-b', 'account-a'], $context),
        $postOperation(['account-a', 'account-b'], $context),
    );

    $versions = array_column($results, 'versions');
    sort($versions);

    expect($results[0]['lockOrder'])->toBe(['account-a', 'account-b'])
        ->and($results[1]['lockOrder'])->toBe(['account-a', 'account-b'])
        ->and($versions)->toBe([[1, 1], [2, 2]]);

    assertDatabaseCount('ledger_transaction', 4);
    assertDatabaseHas('ledger_stream_head', [
        'ledger_type' => 'cash-account',
        'ledger_id' => 'account-a',
        'version' => 2,
    ]);
    assertDatabaseHas('ledger_stream_head', [
        'ledger_type' => 'cash-account',
        'ledger_id' => 'account-b',
        'version' => 2,
    ]);
});

test('concurrent reversals commit exactly one reversal', function () {
    $original = (new Abacus(new PayloadRegistry))
        ->registerLedger(new SimpleLedger)
        ->post('cash-account', '234', stdPayload(), stdContext());
    $context = stdContext();

    $reverse = static function () use ($context, $original): string {
        $abacus = (new Abacus(new PayloadRegistry))->registerLedger(new SimpleLedger);

        try {
            $abacus->reverse($original->id, $context);

            return 'committed';
        } catch (InvalidCorrectionException) {
            return 'already-reversed';
        }
    };

    $results = TwoProcessHarness::run($reverse, $reverse);

    expect($results)->toContain('committed')->toContain('already-reversed');
    assertDatabaseCount('ledger_operation', 2);
    assertDatabaseCount('ledger_transaction', 2);
});

test('concurrent replacements commit exactly one complete replacement', function () {
    $original = (new Abacus(new PayloadRegistry))
        ->registerLedger(new SimpleLedger)
        ->post(
            'cash-account',
            '234',
            GenericPayload::make('deposit', ['amount' => 100]),
            stdContext(),
        );
    $context = stdContext();

    $replace = static function () use ($context, $original): string {
        $abacus = (new Abacus(new PayloadRegistry))->registerLedger(new SimpleLedger);

        try {
            $abacus->replace(
                $original->id,
                GenericPayload::make('deposit', ['amount' => 120]),
                $context,
            );

            return 'committed';
        } catch (InvalidCorrectionException) {
            return 'already-reversed';
        }
    };

    $results = TwoProcessHarness::run($replace, $replace);

    expect($results)->toContain('committed')->toContain('already-reversed');
    assertDatabaseCount('ledger_operation', 2);
    assertDatabaseCount('ledger_transaction', 3);
});

test('concurrent idempotent posts return one committed operation', function () {
    $context = stdContext()->withIdempotencyKey('concurrent:post');
    $post = static function () use ($context): array {
        $transaction = (new Abacus(new PayloadRegistry))
            ->registerLedger(new SimpleLedger)
            ->post(
                'cash-account',
                '234',
                GenericPayload::make('deposit', ['amount' => 2]),
                $context,
                0,
            );

        return [$transaction->operationId, $transaction->id];
    };

    $results = TwoProcessHarness::run($post, $post);

    expect($results[0])->toBe($results[1]);
    assertDatabaseCount('ledger_operation', 1);
    assertDatabaseCount('ledger_transaction', 1);
});

function stdPayload(): GenericPayload
{
    return GenericPayload::make('deposit', [
        'amount' => 2,
    ]);
}

function stdContext(): PostingContext
{
    return new PostingContext(
        'usr-123',
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-01'),
        'paycheck deposit',
    );
}
