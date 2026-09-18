<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Faest\Abacus\Data\CorrectionFilter;
use Faest\Abacus\Data\GenericPayload;
use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Data\TemporalView;
use Faest\Abacus\Data\TransactionCriteria;
use Faest\Abacus\Enums\CorrectionRelationship;
use Faest\Abacus\Enums\ReversalStatus;
use Faest\Abacus\Facades\Abacus;
use Faest\Abacus\Tests\Fixtures\SimpleLedger;

use function Pest\Laravel\assertDatabaseCount;

beforeEach(function () {
    Abacus::registerLedger(new SimpleLedger);
});

it('queries a stream through inclusive temporal views in stream order', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-20 10:00:00'));
    Abacus::post('cash-account', 'one', temporalAmount(10), temporalContext('2026-01-15', '2026-01-31'));

    $this->travelTo(CarbonImmutable::parse('2026-02-10 10:00:00'));
    Abacus::post('cash-account', 'one', temporalAmount(20), temporalContext('2026-01-31', '2026-02-28'));

    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
    Abacus::post('cash-account', 'one', temporalAmount(30), temporalContext('2026-03-01', '2026-01-31'));

    $event = Abacus::transactionsForStream('cash-account', 'one', new TransactionCriteria(
        view: TemporalView::eventAsOf(CarbonImmutable::parse('2026-01-31')),
    ))->get();
    $accounting = Abacus::transactionsForStream('cash-account', 'one', new TransactionCriteria(
        view: TemporalView::accountingAsOf(CarbonImmutable::parse('2026-01-31')),
    ))->get();
    $recorded = Abacus::transactionsForStream('cash-account', 'one', new TransactionCriteria(
        view: TemporalView::current()->knownAt(CarbonImmutable::parse('2026-02-10 10:00:00')),
    ))->get();
    $combined = Abacus::transactionsForStream('cash-account', 'one', new TransactionCriteria(
        view: new TemporalView(
            eventThrough: CarbonImmutable::parse('2026-01-31'),
            accountingThrough: CarbonImmutable::parse('2026-01-31'),
            recordedThrough: CarbonImmutable::parse('2026-02-10 10:00:00'),
        ),
    ))->get();

    expect($event->pluck('stream_version')->all())->toBe([1, 2])
        ->and($accounting->pluck('stream_version')->all())->toBe([1, 3])
        ->and($recorded->pluck('stream_version')->all())->toBe([1, 2])
        ->and($combined->pluck('stream_version')->all())->toBe([1]);
});

it('filters operation correlation and correction relationships exactly', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-20'));
    $original = Abacus::post('cash-account', 'one', temporalAmount(100), temporalContext('2026-01-15', '2026-01-31'));

    $this->travelTo(CarbonImmutable::parse('2026-02-10'));
    $replacement = Abacus::replace(
        $original->id,
        temporalAmount(120),
        temporalContext('2026-01-15', '2026-01-31')->withCorrelationId('5a109e16-1c17-4f22-bac5-4445d5d232a7'),
    );
    $adjustment = Abacus::adjust($replacement->replacementTransaction->id, temporalAmount(5), temporalContext('2026-02-01', '2026-02-28'));

    expect(Abacus::transactionsForStream('cash-account', 'one', new TransactionCriteria(
        operationId: $replacement->operationId,
    ))->pluck('id')->all())->toBe([
        $replacement->reversalTransaction->id,
        $replacement->replacementTransaction->id,
    ])->and(Abacus::transactionsForStream('cash-account', 'one', new TransactionCriteria(
        correlationId: '5a109e16-1c17-4f22-bac5-4445d5d232a7',
    ))->pluck('id')->all())->toBe([
        $replacement->reversalTransaction->id,
        $replacement->replacementTransaction->id,
    ])->and(Abacus::transactionsForStream('cash-account', 'one', new TransactionCriteria(
        correction: new CorrectionFilter(CorrectionRelationship::Reversal, $original->id),
    ))->pluck('id')->all())->toBe([$replacement->reversalTransaction->id])
        ->and(Abacus::transactionsForStream('cash-account', 'one', new TransactionCriteria(
            correction: new CorrectionFilter(CorrectionRelationship::Replacement, $original->id),
        ))->pluck('id')->all())->toBe([$replacement->replacementTransaction->id])
        ->and(Abacus::transactionsForStream('cash-account', 'one', new TransactionCriteria(
            correction: new CorrectionFilter(CorrectionRelationship::Adjustment, $replacement->replacementTransaction->id),
        ))->pluck('id')->all())->toBe([$adjustment->id]);
});

it('evaluates reversal status inside the temporal view and excludes reversal rows', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-20'));
    $original = Abacus::post('cash-account', 'one', temporalAmount(100), temporalContext('2026-01-15', '2026-01-31'));

    $this->travelTo(CarbonImmutable::parse('2026-02-10'));
    $replacement = Abacus::replace($original->id, temporalAmount(120), temporalContext('2026-01-15', '2026-01-31'));

    $past = TemporalView::eventAsOf(
        CarbonImmutable::parse('2026-01-31'),
        CarbonImmutable::parse('2026-01-31 23:59:59'),
    );

    expect(Abacus::transactionsForStream('cash-account', 'one', new TransactionCriteria(
        reversalStatus: ReversalStatus::Reversed,
    ))->pluck('id')->all())->toBe([$original->id])
        ->and(Abacus::transactionsForStream('cash-account', 'one', new TransactionCriteria(
            reversalStatus: ReversalStatus::Unreversed,
        ))->pluck('id')->all())->toBe([$replacement->replacementTransaction->id])
        ->and(Abacus::transactionsForStream('cash-account', 'one', new TransactionCriteria(
            view: $past,
            reversalStatus: ReversalStatus::Reversed,
        ))->count())->toBe(0)
        ->and(Abacus::transactionsForStream('cash-account', 'one', new TransactionCriteria(
            view: $past,
            reversalStatus: ReversalStatus::Unreversed,
        ))->pluck('id')->all())->toBe([$original->id]);
});

it('reconstructs backdated aggregates with current and historical knowledge', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-20'));
    $original = Abacus::post('cash-account', 'one', temporalAmount(100), temporalContext('2026-01-15', '2026-01-31'));

    $this->travelTo(CarbonImmutable::parse('2026-02-10'));
    Abacus::replace($original->id, temporalAmount(120), temporalContext('2026-01-15', '2026-01-31'));

    $knownThen = CarbonImmutable::parse('2026-01-31 23:59:59');

    expect(Abacus::getAggregate('cash-account', 'one'))->toBe(['total' => 120])
        ->and(Abacus::getAggregate('cash-account', 'one', TemporalView::current()))->toBe(['total' => 120])
        ->and(Abacus::getAggregate('cash-account', 'one', TemporalView::eventAsOf(CarbonImmutable::parse('2026-01-31'))))->toBe(['total' => 120])
        ->and(Abacus::getAggregate('cash-account', 'one', TemporalView::accountingAsOf(CarbonImmutable::parse('2026-01-31'))))->toBe(['total' => 120])
        ->and(Abacus::getAggregate('cash-account', 'one', TemporalView::eventAsOf(CarbonImmutable::parse('2026-01-31'), $knownThen)))->toBe(['total' => 100])
        ->and(Abacus::getAggregate('cash-account', 'one', TemporalView::accountingAsOf(CarbonImmutable::parse('2026-01-31'), $knownThen)))->toBe(['total' => 100])
        ->and(Abacus::getAggregate('cash-account', 'one', TemporalView::current()->knownAt(CarbonImmutable::parse('2026-01-19'))))->toBe(['total' => 0]);
});

it('does not enforce present-day aggregate invariants on historical reads', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-02'));
    Abacus::post('cash-account', 'one', temporalAmount(10), temporalContext('2026-02-01', '2026-02-01'));
    Abacus::post('cash-account', 'one', temporalAmount(-10), temporalContext('2026-01-01', '2026-01-01'));

    expect(Abacus::getAggregate(
        'cash-account',
        'one',
        TemporalView::eventAsOf(CarbonImmutable::parse('2026-01-31')),
    ))->toBe(['total' => -10]);
});

it('returns no transactions and creates no head for an unknown stream', function () {
    expect(Abacus::transactionsForStream('cash-account', 'missing')->get())->toBeEmpty()
        ->and(Abacus::getAggregate('cash-account', 'missing'))->toBe(['total' => 0]);

    assertDatabaseCount('ledger_stream_head', 0);
});

it('rejects empty optional criteria identifiers', function () {
    expect(fn () => new TransactionCriteria(operationId: ''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new TransactionCriteria(correlationId: ''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CorrectionFilter(CorrectionRelationship::Reversal, ''))->toThrow(InvalidArgumentException::class);
});

function temporalAmount(int $amount): GenericPayload
{
    return GenericPayload::make('entry', ['amount' => $amount]);
}

function temporalContext(string $eventDate, string $accountingDate): PostingContext
{
    return PostingContext::forProcess(
        'temporal-test',
        CarbonImmutable::parse($eventDate),
        CarbonImmutable::parse($accountingDate),
        'temporal test',
    );
}
