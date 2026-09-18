<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Faest\Abacus\Data\GenericPayload;
use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Enums\OperationKind;
use Faest\Abacus\Exceptions\FailedInvariantException;
use Faest\Abacus\Exceptions\IdempotencyConflictException;
use Faest\Abacus\Exceptions\UnexpectedStreamVersionException;
use Faest\Abacus\Facades\Abacus;
use Faest\Abacus\Models\LedgerOperation;
use Faest\Abacus\OperationBuilder;
use Faest\Abacus\Tests\Fixtures\SimpleLedger;
use Illuminate\Support\Facades\DB;
use Workbench\App\Models\User;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    Abacus::registerLedger(new SimpleLedger);
});

it('records explicit posting context and toolkit time', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-02'));

    $transaction = Abacus::post('cash-account', 'acct-1', ledgerAmount(10), ledgerContext());

    assertDatabaseHas('ledger_operation', [
        'id' => $transaction->operationId,
        'kind' => OperationKind::Posting->value,
        'actor' => 'user:usr-123',
        'event_date' => '2026-06-01 00:00:00',
        'accounting_date' => '2026-06-01 00:00:00',
        'system_date' => '2026-06-02 00:00:00',
        'reason' => 'paycheck deposit',
    ]);
    assertDatabaseHas('ledger_transaction', [
        'id' => $transaction->id,
        'operation_id' => $transaction->operationId,
        'operation_position' => 1,
        'stream_version' => 1,
        'actor' => 'user:usr-123',
        'system_date' => '2026-06-02 00:00:00',
    ]);
});

it('uses the authenticated user when context is omitted', function () {
    $this->actingAs(ledgerUser());

    $transaction = Abacus::post('cash-account', 'acct-1', ledgerAmount(10));

    assertDatabaseHas('ledger_operation', [
        'id' => $transaction->operationId,
        'actor' => 'user:89',
    ]);
});

it('validates the completed aggregate and rolls back the operation', function () {
    expect(fn () => Abacus::postMany(
        'cash-account',
        'acct-1',
        [ledgerAmount(10), ledgerAmount(-20)],
        ledgerContext(),
    ))->toThrow(function (FailedInvariantException $exception): void {
        expect($exception->ledgerType)->toBe('cash-account')
            ->and($exception->ledgerId)->toBe('acct-1');
    });

    assertDatabaseCount('ledger_operation', 0);
    assertDatabaseCount('ledger_transaction', 0);
    assertDatabaseCount('ledger_stream_head', 0);
});

it('transfers between streams in one semantic operation', function () {
    Abacus::post('cash-account', 'source', ledgerAmount(50), ledgerContext());
    Abacus::post('cash-account', 'destination', ledgerAmount(10), ledgerContext());

    $transfer = Abacus::transfer(
        ledgerAmount(-15, 'transfer'),
        'source',
        'destination',
        'cash-account',
        ledgerContext()->withReason('move cash'),
    );

    expect($transfer->operationId)->toBe($transfer->sourceTransaction->operationId)
        ->and($transfer->operationId)->toBe($transfer->destinationTransaction->operationId)
        ->and($transfer->correlationId)->not->toBeEmpty()
        ->and(LedgerOperation::query()->findOrFail($transfer->operationId)->kind)->toBe(OperationKind::Transfer)
        ->and(Abacus::getAggregate('cash-account', 'source'))->toBe(['total' => 35])
        ->and(Abacus::getAggregate('cash-account', 'destination'))->toBe(['total' => 25]);
});

it('reverses every entry of an operation by operation id', function () {
    Abacus::post('cash-account', 'source', ledgerAmount(50), ledgerContext());
    Abacus::post('cash-account', 'destination', ledgerAmount(50), ledgerContext());
    $transfer = Abacus::transfer(
        ledgerAmount(-15, 'transfer'),
        'source',
        'destination',
        'cash-account',
        ledgerContext(),
    );

    $reversals = Abacus::reverseOperation($transfer->operationId, ledgerContext()->withReason('undo transfer'));

    expect($reversals)->toHaveCount(2)
        ->and(LedgerOperation::query()->findOrFail($reversals[0]->operationId)->kind)
        ->toBe(OperationKind::OperationReversal)
        ->and(Abacus::getAggregate('cash-account', 'source'))->toBe(['total' => 50])
        ->and(Abacus::getAggregate('cash-account', 'destination'))->toBe(['total' => 50]);
});

it('rejects negative and stale expected versions', function () {
    expect(fn () => Abacus::post(
        'cash-account',
        'acct-1',
        ledgerAmount(10),
        ledgerContext(),
        -1,
    ))->toThrow(InvalidArgumentException::class);

    Abacus::post('cash-account', 'acct-1', ledgerAmount(10), ledgerContext(), 0);

    expect(fn () => Abacus::post(
        'cash-account',
        'acct-1',
        ledgerAmount(10),
        ledgerContext(),
        0,
    ))->toThrow(function (UnexpectedStreamVersionException $exception): void {
        expect($exception->expectedVersion)->toBe(0)
            ->and($exception->actualVersion)->toBe(1);
    });
});

it('reports and advances stream versions', function () {
    expect(Abacus::streamVersion('cash-account', 'acct-1'))->toBe(0);

    Abacus::postMany(
        'cash-account',
        'acct-1',
        [ledgerAmount(1), ledgerAmount(2)],
        ledgerContext(),
    );

    expect(Abacus::streamVersion('cash-account', 'acct-1'))->toBe(2);
});

it('replays an operation when a stale expected version accompanies identical intent', function () {
    $context = ledgerContext()->withIdempotencyKey('payroll:42');
    $first = Abacus::post('cash-account', 'acct-1', ledgerAmount(10), $context, 0);

    Abacus::post('cash-account', 'acct-1', ledgerAmount(5), ledgerContext());

    $replayed = Abacus::post('cash-account', 'acct-1', ledgerAmount(10), $context, 0);

    expect($replayed)->toEqual($first)
        ->and(LedgerOperation::query()->where('idempotency_key', 'payroll:42')->count())->toBe(1);
});

it('uses operation-scoped idempotency across ledger types and targets', function () {
    $context = ledgerContext()->withIdempotencyKey('external:42');
    Abacus::post('cash-account', 'acct-1', ledgerAmount(10), $context);

    expect(fn () => Abacus::post('cash-account', 'acct-2', ledgerAmount(10), $context))
        ->toThrow(function (IdempotencyConflictException $exception): void {
            expect($exception->idempotencyKey)->toBe('external:42')
                ->and($exception->existingOperationId)->not->toBeEmpty();
        });
});

it('replays import contexts created independently for the same source batch', function () {
    $firstContext = PostingContext::forImport(
        'fleetio',
        'batch-42',
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-01'),
        'Fleetio import',
    );
    $retryContext = PostingContext::forImport(
        'fleetio',
        'batch-42',
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-01'),
        'Fleetio import',
    );

    $first = Abacus::post('cash-account', 'acct-1', ledgerAmount(10), $firstContext);
    $retry = Abacus::post('cash-account', 'acct-1', ledgerAmount(10), $retryContext);

    expect($retry)->toEqual($first)
        ->and($firstContext->correlationId)->toBeNull()
        ->and($retryContext->correlationId)->toBeNull();
});

it('rolls operation and domain writes back with an outer transaction', function () {
    try {
        DB::transaction(function (): void {
            Abacus::operation(ledgerContext(), function (OperationBuilder $operation): void {
                $operation->post('cash-account', 'acct-1', ledgerAmount(10));
                $operation->post('cash-account', 'acct-2', ledgerAmount(20));
            });

            throw new RuntimeException('domain write failed');
        });
    } catch (RuntimeException) {
        // Expected domain rollback.
    }

    assertDatabaseCount('ledger_operation', 0);
    assertDatabaseCount('ledger_transaction', 0);
});

it('queries complete operations touching a stream', function () {
    $first = Abacus::post('cash-account', 'acct-1', ledgerAmount(10), ledgerContext());
    $second = Abacus::operation(ledgerContext(), function (OperationBuilder $operation): void {
        $operation->post('cash-account', 'acct-1', ledgerAmount(5));
        $operation->post('cash-account', 'acct-2', ledgerAmount(7));
    });

    $operations = Abacus::operationsForStream('cash-account', 'acct-1')->get();

    expect($operations->pluck('id')->all())->toBe([$first->operationId, $second->id])
        ->and($operations[1]->transactions)->toHaveCount(2);
});

function ledgerAmount(int $amount, string $type = 'entry'): GenericPayload
{
    return GenericPayload::make($type, ['amount' => $amount]);
}

function ledgerContext(): PostingContext
{
    return PostingContext::forUser(
        'usr-123',
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-01'),
        'paycheck deposit',
    );
}

function ledgerUser(): User
{
    $user = new User;
    $user->forceFill(['id' => '89']);

    return $user;
}
