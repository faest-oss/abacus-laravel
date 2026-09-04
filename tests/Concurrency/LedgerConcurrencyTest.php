<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Data\GenericPayload;
use Faest\Abacus\Data\TransactionDraft;
use Faest\Abacus\Models\LedgerTransaction;
use Faest\Abacus\Tests\Fixtures\SimpleLedger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function PHPUnit\Framework\assertTrue;

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

test('posts are serialized', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-02'));

    $ledger1 = new SimpleLedger;
    $ledger1->overrideConnection('pgsql');
    $ledger2 = new SimpleLedger;
    $ledger2->overrideLockTimeout(0);
    $ledger2->overrideConnection('pgsql2');

    $lockTested = false;
    DB::connection('pgsql')->listen(function ($query) use (&$lockTested, $ledger2) {
        if (str_contains(strtolower($query->sql), 'for update')) {
            try {
                $ledger2->post(stdTrans());
            } catch (QueryException $e) {
                expect($e->getCode())->toBe('55P03');
                $lockTested = true;
            }
        }
    });

    $ledger1->post(stdTrans(payload: stdPayload(payload: ['amount' => 25])));
    assertTrue($lockTested);
    assertDatabaseCount('ledger_transaction', 1);
    assertDatabaseHas('ledger_transaction', stdDbRecord());
    expect(LedgerTransaction::on('pgsql')->sole()->payload)->toBe(['amount' => 25]);
});

function stdTrans(string $ledgerId = '234', ?LedgerPayload $payload = null): TransactionDraft
{
    $payload = $payload ? $payload : GenericPayload::make('deposit', [
        'amount' => 2,
    ]);

    return TransactionDraft::make($ledgerId, $payload)
        ->authoredBy('usr-123')
        ->withReason('paycheck deposit')
        ->occurredAt(CarbonImmutable::parse('2026-06-01'))
        ->bookedFor(CarbonImmutable::parse('2026-06-01'));
}

/**
 * @param  array<string, mixed>  $payload
 */
function stdPayload(
    string $payloadType = 'deposit',
    array $payload = [],
): LedgerPayload {
    return GenericPayload::make($payloadType, array_merge([
        'amount' => 2,
    ], $payload));
}

/**
 * @param  array<string, mixed>  $record
 * @return array<mixed>
 */
function stdDbRecord(array $record = []): array
{
    return array_merge(
        [
            'entered_by_user_id' => 'usr-123',
            'recorded_at' => '2026-06-02 00:00:00',
            'reverses_transaction_id' => null,
            'adjusts_transaction_id' => null,
            'correlation_id' => null,
            'payload_type' => 'deposit',
            'ledger_type' => 'cash-account',
            'ledger_id' => '234',
            'effective_at' => '2026-06-01 00:00:00',
            'accounting_date' => '2026-06-01 00:00:00',
            'reason' => 'paycheck deposit',
            'stream_version' => 1,
        ],
        $record,
    );
}
