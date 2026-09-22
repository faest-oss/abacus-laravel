<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Faest\Abacus\Data\GenericPayload;
use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Enums\OperationKind;
use Faest\Abacus\Exceptions\EmptyOperationException;
use Faest\Abacus\Exceptions\IdempotencyConflictException;
use Faest\Abacus\Exceptions\InvalidCorrectionException;
use Faest\Abacus\Facades\Abacus;
use Faest\Abacus\Models\LedgerOperation;
use Faest\Abacus\Models\LedgerTransaction;
use Faest\Abacus\OperationBuilder;
use Faest\Abacus\Tests\Fixtures\RestrictedCorrectionLedger;
use Faest\Abacus\Tests\Fixtures\SimpleLedger;

use function Pest\Laravel\assertDatabaseCount;

beforeEach(function () {
    Abacus::registerLedger(new SimpleLedger);
});

it('posts one entry as an immutable posting operation', function () {
    $transaction = Abacus::post(
        'cash-account',
        'acct-1',
        amount(10),
        operationContext(),
    );

    expect($transaction->version)->toBe(1)
        ->and($transaction->operationPosition)->toBe(1);

    $operation = LedgerOperation::query()->sole();

    expect($transaction->operationId)->toBe($operation->id)
        ->and($operation->kind)->toBe(OperationKind::Posting)
        ->and($operation->transactions)->toHaveCount(1);
});

it('builds immutable posting metadata fluently', function () {
    $context = operationContext()
        ->withMetadata(['source' => 'first', 'retained' => true])
        ->mergeMetadata(['source' => 'second']);

    expect($context->metadata)->toBe(['source' => 'second', 'retained' => true])
        ->and(operationContext()->metadata)->toBe([]);
});

it('posts many ordinary entries to one stream in one operation', function () {
    $transactions = Abacus::postMany(
        'cash-account',
        'acct-1',
        [amount(10), amount(-4)],
        operationContext(),
        expectedVersion: 0,
    );

    expect(array_column($transactions, 'version'))->toBe([1, 2])
        ->and(array_column($transactions, 'operationPosition'))->toBe([1, 2])
        ->and(array_unique(array_column($transactions, 'operationId')))->toHaveCount(1)
        ->and(Abacus::getAggregate('cash-account', 'acct-1'))->toBe(['total' => 6]);
});

it('rejects empty posting and composed operations', function () {
    expect(fn () => Abacus::postMany('cash-account', 'acct-1', [], operationContext()))
        ->toThrow(EmptyOperationException::class)
        ->and(fn () => Abacus::operation(operationContext(), function (OperationBuilder $operation): void {
            // Intentionally empty.
        }))->toThrow(EmptyOperationException::class);

    assertDatabaseCount('ledger_operation', 0);
});

it('composes postings across streams with the operation builder', function () {
    $result = Abacus::operation(operationContext(), function (OperationBuilder $operation): void {
        $operation->post('cash-account', 'acct-1', amount(10));
        $operation->post('cash-account', 'acct-2', amount(20));
    });

    expect($result->kind)->toBe(OperationKind::Posting)
        ->and($result->transactions)->toHaveCount(2)
        ->and(Abacus::getAggregate('cash-account', 'acct-1'))->toBe(['total' => 10])
        ->and(Abacus::getAggregate('cash-account', 'acct-2'))->toBe(['total' => 20]);
});

it('replaces an entry using completed-operation invariant validation', function () {
    $original = Abacus::post('cash-account', 'acct-1', amount(100), operationContext());
    Abacus::post('cash-account', 'acct-1', amount(-80), operationContext());

    $replacement = Abacus::replace(
        $original->id,
        amount(120),
        operationContext()->withReason('correct amount'),
        expectedVersion: 2,
    );

    expect($replacement->reversalTransaction->version)->toBe(3)
        ->and($replacement->replacementTransaction->version)->toBe(4)
        ->and($replacement->reversalTransaction->operationId)->toBe($replacement->operationId)
        ->and($replacement->replacementTransaction->operationId)->toBe($replacement->operationId)
        ->and(Abacus::getAggregate('cash-account', 'acct-1'))->toBe(['total' => 40]);

    $rows = LedgerTransaction::query()
        ->where('operation_id', $replacement->operationId)
        ->orderBy('operation_position')
        ->get();

    expect($rows[0]->reverses_transaction_id)->toBe($original->id)
        ->and($rows[1]->replaces_transaction_id)->toBe($original->id);
});

it('does not allow a reversal to be reversed', function () {
    $original = Abacus::post('cash-account', 'acct-1', amount(20), operationContext());
    $reversal = Abacus::reverse($original->id, operationContext());

    expect(fn () => Abacus::reverse($reversal->id, operationContext()))
        ->toThrow(InvalidCorrectionException::class);
});

it('allows only one full reversal and rolls back the rejected operation', function () {
    $original = Abacus::post('cash-account', 'acct-1', amount(20), operationContext());
    Abacus::reverse($original->id, operationContext());

    expect(fn () => Abacus::reverse($original->id, operationContext()))
        ->toThrow(InvalidCorrectionException::class);

    assertDatabaseCount('ledger_operation', 2);
    assertDatabaseCount('ledger_transaction', 2);
});

it('rolls back both replacement entries when the completed aggregate is invalid', function () {
    $original = Abacus::post('cash-account', 'acct-1', amount(100), operationContext());

    expect(fn () => Abacus::replace($original->id, amount(-10), operationContext()))
        ->toThrow(Exception::class, 'Cannot be negative');

    assertDatabaseCount('ledger_operation', 1);
    assertDatabaseCount('ledger_transaction', 1);
    expect(Abacus::getAggregate('cash-account', 'acct-1'))->toBe(['total' => 100]);
});

it('supports replacement chains by targeting the latest replacement entry', function () {
    $original = Abacus::post('cash-account', 'acct-1', amount(100), operationContext());
    $first = Abacus::replace($original->id, amount(120), operationContext());
    $second = Abacus::replace($first->replacementTransaction->id, amount(130), operationContext());

    expect($second->replacementTransaction->version)->toBe(5)
        ->and(Abacus::getAggregate('cash-account', 'acct-1'))->toBe(['total' => 130]);
});

it('allows multiple adjustments and leaves them when the original is reversed', function () {
    $original = Abacus::post('cash-account', 'acct-1', amount(100), operationContext());

    Abacus::adjust($original->id, amount(10), operationContext());
    Abacus::adjust($original->id, amount(-5), operationContext());
    Abacus::reverse($original->id, operationContext());

    expect(Abacus::getAggregate('cash-account', 'acct-1'))->toBe(['total' => 5]);
});

it('allows a ledger correction policy to reject an adjustment', function () {
    Abacus::registerLedger(new RestrictedCorrectionLedger);
    $original = Abacus::post('restricted-account', 'acct-1', amount(100), operationContext());

    expect(fn () => Abacus::adjust($original->id, amount(10), operationContext()))
        ->toThrow(DomainException::class, 'Adjustments are not permitted.');

    assertDatabaseCount('ledger_operation', 1);
    assertDatabaseCount('ledger_transaction', 1);
});

it('derives composite kind for mixed semantic actions', function () {
    $original = Abacus::post('cash-account', 'acct-1', amount(20), operationContext());

    $result = Abacus::operation(operationContext(), function (OperationBuilder $operation) use ($original): void {
        $operation->adjust($original->id, amount(5));
        $operation->post('cash-account', 'acct-2', amount(10));
    });

    expect($result->kind)->toBe(OperationKind::Composite);
});

it('replays a matching operation idempotently and rejects changed intent', function () {
    $context = operationContext()->withIdempotencyKey('source:42');

    $first = Abacus::post('cash-account', 'acct-1', amount(10), $context, expectedVersion: 0);
    $replayed = Abacus::post('cash-account', 'acct-1', amount(10), $context, expectedVersion: 0);

    expect($replayed)->toEqual($first)
        ->and(LedgerOperation::query()->count())->toBe(1)
        ->and(LedgerTransaction::query()->count())->toBe(1)
        ->and(fn () => Abacus::post('cash-account', 'acct-1', amount(11), $context))
        ->toThrow(IdempotencyConflictException::class);
});

it('treats postMany and operation as the same idempotent posting intent', function () {
    $context = operationContext()->withIdempotencyKey('source:method-independent');
    $posted = Abacus::postMany('cash-account', 'acct-1', [amount(10)], $context);

    $replayed = Abacus::operation($context, function (OperationBuilder $operation): void {
        $operation->post('cash-account', 'acct-1', amount(10));
    });

    expect($replayed->id)->toBe($posted[0]->operationId)
        ->and($replayed->transactions[0])->toEqual($posted[0]);
    assertDatabaseCount('ledger_operation', 1);
    assertDatabaseCount('ledger_transaction', 1);
});

it('finds operation detail and reconstructs a stream at its boundary', function () {
    $first = Abacus::post('cash-account', 'acct-1', amount(10), operationContext());
    Abacus::post('cash-account', 'acct-1', amount(5), operationContext());

    $operation = Abacus::findOperation($first->operationId);

    expect($operation?->id)->toBe($first->operationId)
        ->and($operation?->transactions)->toHaveCount(1)
        ->and(Abacus::getAggregateAtOperation('cash-account', 'acct-1', $first->operationId))
        ->toBe(['total' => 10]);
});

function amount(int $amount, string $type = 'entry'): GenericPayload
{
    return GenericPayload::make($type, ['amount' => $amount]);
}

function operationContext(): PostingContext
{
    return PostingContext::forUser(
        'usr-123',
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-01'),
        'test operation',
    );
}
