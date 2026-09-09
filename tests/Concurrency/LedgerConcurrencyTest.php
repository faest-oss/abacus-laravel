<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Data\GenericPayload;
use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Data\TransactionDraft;
use Faest\Abacus\Exceptions\UnexpectedStreamVersionException;
use Faest\Abacus\Models\LedgerTransaction;
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
    DB::connection()->table('ledger_transaction')->truncate();
    DB::connection()->table('ledger_stream_head')->truncate();
});

test('posts acquire an exclusive stream head lock', function () {
    $firstLedger = new SimpleLedger();
    $firstLedger->overrideConnection('pgsql');

    $secondLedger = new SimpleLedger();
    $secondLedger->overrideConnection('pgsql2');
    $secondLedger->overrideLockTimeout(0);

    $lockWasContended = false;

    DB::connection('pgsql')->listen(function ($query) use ($secondLedger, &$lockWasContended) {
        if (! str_contains(strtolower($query->sql), 'for update')) {
            return;
        }

        try {
            $secondLedger->post(stdTrans()->failIfVersionIsnt(0), stdContext());
        } catch (QueryException $exception) {
            expect($exception->getCode())->toBe('55P03');
            $lockWasContended = true;
        }
    });

    $firstLedger->post(stdTrans(
        payload: GenericPayload::make('deposit', ['amount' => 25]),
    )->failIfVersionIsnt(0), stdContext());

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
    $draft = stdTrans()->failIfVersionIsnt(0);
    $context = stdContext();
    $post = static function () use ($draft, $context): array {
        $ledger = new SimpleLedger();

        try {
            $transaction = $ledger->post($draft, $context);

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

test('opposing post many calls acquire stream locks in the same order', function () {
    $firstDrafts = [stdTrans('account-b'), stdTrans('account-a')];
    $secondDrafts = [stdTrans('account-a'), stdTrans('account-b')];

    $postMany = static function (array $drafts, PostingContext $context): Closure {
        return static function () use ($drafts, $context): array {
            $lockOrder = [];

            DB::listen(static function ($query) use (&$lockOrder) {
                if (str_contains(strtolower($query->sql), 'for update')) {
                    $lockOrder[] = (string) end($query->bindings);
                }
            });

            $transactions = (new SimpleLedger())->postMany($drafts, $context);

            return [
                'lockOrder' => array_slice($lockOrder, 0, 2),
                'versions' => array_map(
                    static fn($transaction): int => $transaction->version,
                    $transactions,
                ),
            ];
        };
    };

    $context = stdContext();

    $results = TwoProcessHarness::run(
        $postMany($firstDrafts, $context),
        $postMany($secondDrafts, $context),
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

function stdTrans(string $ledgerId = '234', ?LedgerPayload $payload = null): TransactionDraft
{
    $payload = $payload ? $payload : GenericPayload::make('deposit', [
        'amount' => 2,
    ]);

    return TransactionDraft::make('cash-account', $ledgerId, $payload);
}

function stdContext(): PostingContext
{
    return new PostingContext(
        'usr-123',
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-01'),
        'paycheck deposit'
    );
}
