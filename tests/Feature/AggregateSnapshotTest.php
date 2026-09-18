<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Faest\Abacus\Data\GenericPayload;
use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Data\TemporalView;
use Faest\Abacus\Facades\Abacus;
use Faest\Abacus\Models\LedgerSnapshot;
use Faest\Abacus\Tests\Fixtures\SimpleLedger;
use Faest\Abacus\Tests\Fixtures\SnapshotLedger;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    Abacus::registerLedger(new SnapshotLedger);
});

it('creates an idempotent snapshot at the locked final stream boundary', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-20'));
    Abacus::postMany(
        'snapshot-account',
        'one',
        [snapshotAmount(10), snapshotAmount(20)],
        snapshotContext('2026-01-15', '2026-01-31'),
    );

    expect(Abacus::createAggregateSnapshot('snapshot-account', 'one'))->toBe(2)
        ->and(Abacus::createAggregateSnapshot('snapshot-account', 'one'))->toBe(2)
        ->and(Abacus::getAggregate('snapshot-account', 'one'))->toBe(['total' => 30]);

    assertDatabaseCount('ledger_snapshot', 1);
    assertDatabaseHas('ledger_snapshot', [
        'ledger_type' => 'snapshot-account',
        'ledger_id' => 'one',
        'stream_version' => 2,
        'snapshot_version' => 1,
        'max_event_date' => '2026-01-15 00:00:00',
        'max_accounting_date' => '2026-01-31 00:00:00',
        'max_system_date' => '2026-01-20 00:00:00',
    ]);
    expect(LedgerSnapshot::query()->sole()->aggregate)->toBe(['total' => 30]);
});

it('returns zero for an empty stream and rejects unsupported ledgers and versions', function () {
    expect(Abacus::createAggregateSnapshot('snapshot-account', 'missing'))->toBe(0);
    assertDatabaseCount('ledger_stream_head', 0);
    assertDatabaseCount('ledger_snapshot', 0);

    Abacus::registerLedger(new SimpleLedger);
    expect(fn () => Abacus::createAggregateSnapshot('cash-account', 'one'))
        ->toThrow(InvalidArgumentException::class);

    Abacus::registerLedger(new SnapshotLedger(0));
    expect(fn () => Abacus::createAggregateSnapshot('snapshot-account', 'one'))
        ->toThrow(InvalidArgumentException::class);
});

it('uses eligible snapshots and replays later backdated entries', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));
    Abacus::post('snapshot-account', 'one', snapshotAmount(10), snapshotContext('2026-03-01', '2026-03-01'));
    Abacus::createAggregateSnapshot('snapshot-account', 'one');

    $this->travelTo(CarbonImmutable::parse('2026-04-01'));
    Abacus::post('snapshot-account', 'one', snapshotAmount(20), snapshotContext('2026-01-01', '2026-01-01'));

    expect(Abacus::getAggregate('snapshot-account', 'one'))->toBe(['total' => 30])
        ->and(Abacus::getAggregate(
            'snapshot-account',
            'one',
            TemporalView::eventAsOf(CarbonImmutable::parse('2026-01-31')),
        ))->toBe(['total' => 20])
        ->and(Abacus::getAggregate(
            'snapshot-account',
            'one',
            TemporalView::accountingAsOf(CarbonImmutable::parse('2026-01-31')),
        ))->toBe(['total' => 20]);
});

it('rejects a snapshot whose recorded maximum exceeds the view', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));
    Abacus::post('snapshot-account', 'one', snapshotAmount(10), snapshotContext('2026-01-01', '2026-01-01'));
    Abacus::createAggregateSnapshot('snapshot-account', 'one');

    $this->travelTo(CarbonImmutable::parse('2026-01-15'));
    Abacus::post('snapshot-account', 'one', snapshotAmount(20), snapshotContext('2026-01-01', '2026-01-01'));

    expect(Abacus::getAggregate(
        'snapshot-account',
        'one',
        TemporalView::current()->knownAt(CarbonImmutable::parse('2026-01-31')),
    ))->toBe(['total' => 20]);
});

it('ignores unsupported snapshot formats and propagates hydration failures', function () {
    Abacus::post('snapshot-account', 'one', snapshotAmount(10), snapshotContext('2026-01-01', '2026-01-01'));
    Abacus::createAggregateSnapshot('snapshot-account', 'one');

    Abacus::registerLedger(new SnapshotLedger(2));
    expect(Abacus::getAggregate('snapshot-account', 'one'))->toBe(['total' => 10]);

    Abacus::registerLedger(new SnapshotLedger(1, failHydration: true));
    expect(fn () => Abacus::getAggregate('snapshot-account', 'one'))
        ->toThrow(RuntimeException::class, 'Snapshot hydration failed.');

    Abacus::registerLedger(new SnapshotLedger);
    DB::table('ledger_snapshot')->update(['aggregate' => json_encode('not-an-array')]);
    expect(fn () => Abacus::getAggregate('snapshot-account', 'one'))
        ->toThrow(UnexpectedValueException::class, 'must decode to an array');
});

it('rolls back failed serialization and insertion without changing history', function () {
    Abacus::post('snapshot-account', 'one', snapshotAmount(10), snapshotContext('2026-01-01', '2026-01-01'));
    Abacus::registerLedger(new SnapshotLedger(failSerialization: true));

    expect(fn () => Abacus::createAggregateSnapshot('snapshot-account', 'one'))
        ->toThrow(RuntimeException::class, 'Snapshot serialization failed.');
    assertDatabaseCount('ledger_snapshot', 0);
    assertDatabaseCount('ledger_transaction', 1);

    Abacus::registerLedger(new SnapshotLedger);
    LedgerSnapshot::creating(fn () => throw new RuntimeException('Snapshot insert failed.'));
    expect(fn () => Abacus::createAggregateSnapshot('snapshot-account', 'one'))
        ->toThrow(RuntimeException::class, 'Snapshot insert failed.');
    assertDatabaseCount('ledger_snapshot', 0);
    assertDatabaseCount('ledger_transaction', 1);
});

it('uses only final operation boundaries for snapshots and historical aggregate reads', function () {
    $first = Abacus::post('snapshot-account', 'one', snapshotAmount(10), snapshotContext('2026-01-01', '2026-01-01'));
    Abacus::createAggregateSnapshot('snapshot-account', 'one');
    Abacus::postMany(
        'snapshot-account',
        'one',
        [snapshotAmount(20), snapshotAmount(30)],
        snapshotContext('2026-02-01', '2026-02-01'),
    );

    expect(Abacus::getAggregateAtOperation('snapshot-account', 'one', $first->operationId))
        ->toBe(['total' => 10]);
});

it('does not create snapshots during ordinary writes', function () {
    Abacus::post('snapshot-account', 'one', snapshotAmount(10), snapshotContext('2026-01-01', '2026-01-01'));
    assertDatabaseCount('ledger_snapshot', 0);
});

it('allows disposable snapshots to be deleted without changing aggregate meaning', function () {
    Abacus::post('snapshot-account', 'one', snapshotAmount(10), snapshotContext('2026-01-01', '2026-01-01'));
    Abacus::createAggregateSnapshot('snapshot-account', 'one');

    LedgerSnapshot::query()->sole()->delete();

    assertDatabaseCount('ledger_snapshot', 0);
    expect(Abacus::getAggregate('snapshot-account', 'one'))->toBe(['total' => 10]);
});

function snapshotAmount(int $amount): GenericPayload
{
    return GenericPayload::make('entry', ['amount' => $amount]);
}

function snapshotContext(string $eventDate, string $accountingDate): PostingContext
{
    return PostingContext::forProcess(
        'snapshot-test',
        CarbonImmutable::parse($eventDate),
        CarbonImmutable::parse($accountingDate),
        'snapshot test',
    );
}
