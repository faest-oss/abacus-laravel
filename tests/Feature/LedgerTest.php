<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Faest\Abacus\Data\GenericPayload;
use Faest\Abacus\Data\TransactionDraft;
use Faest\Abacus\Data\VoidDraft;
use Faest\Abacus\Tests\Fixtures\SimpleLedger;
use Workbench\App\Models\User;

it('posts transactions', function () {
    $ledger = new SimpleLedger();

    $this->assertDatabaseCount('ledger_transaction', 0);
    $time = CarbonImmutable::parse('2026-06-02');
    $this->travelTo($time);

    $this->actingAs(fakeUser());

    $draft = TransactionDraft::make('acct-123', GenericPayload::make('deposit', [
        'amount' => 1,
    ]))
        ->withReason('weekly deposit')
        ->occurredAt(CarbonImmutable::parse('2026-06-01'))
        ->bookedFor(CarbonImmutable::parse('2026-06-03'));

    $ledger->post($draft);

    $this->assertDatabaseCount('ledger_transaction', 1);

    $this->assertDatabaseHas('ledger_transaction', [
        'entered_by_user_id' => '89',
        'recorded_at' => '2026-06-02 00:00:00',
        'reverses_transaction_id' => null,
        'adjusts_transaction_id' => null,
        'correlation_id' => null,
        'payload_type' => 'deposit',
        'ledger_type' => 'cash-account',
        'ledger_id' => 'acct-123',
        'effective_at' => '2026-06-01 00:00:00',
        'accounting_date' => '2026-06-03 00:00:00',
        'reason' => 'weekly deposit',
    ]);
});

it('enforces domain invariants', function () {
    $ledger = new SimpleLedger();

    $this->assertDatabaseCount('ledger_transaction', 0);
    $time = CarbonImmutable::parse('2026-06-02');
    $this->travelTo($time);

    $this->actingAs(fakeUser());

    $deposit = TransactionDraft::make('acct-123', GenericPayload::make('deposit', [
        'amount' => 1,
    ]))
        ->withReason('weekly deposit')
        ->occurredAt(CarbonImmutable::parse('2026-06-01'))
        ->bookedFor(CarbonImmutable::parse('2026-06-03'));

    $ledger->post($deposit);

    $overdraft = fn() => $ledger->post(TransactionDraft::make('acct-123', GenericPayload::make('withdraw', [
        'amount' => -2,
    ]))
        ->withReason('atm withdraw')
        ->occurredAt(CarbonImmutable::parse('2026-06-04'))
        ->bookedFor(CarbonImmutable::parse('2026-06-04')));

    expect($overdraft)
        ->toThrow(Exception::class, 'Cannot be negative');

    $this->assertDatabaseCount('ledger_transaction', 1);

    $this->assertDatabaseHas('ledger_transaction', [
        'entered_by_user_id' => '89',
        'recorded_at' => '2026-06-02 00:00:00',
        'reverses_transaction_id' => null,
        'adjusts_transaction_id' => null,
        'payload_type' => 'deposit',
        'ledger_type' => 'cash-account',
        'ledger_id' => 'acct-123',
        'effective_at' => '2026-06-01 00:00:00',
        'reason' => 'weekly deposit',
    ]);
});

it('calculates aggregates', function () {
    $ledger = new SimpleLedger();

    $this->actingAs(fakeUser());

    $ledger->post(TransactionDraft::make('acct-123', GenericPayload::make('deposit', [
        'amount' => 1,
    ]))
        ->withReason('weekly dep')
        ->occurredAt(CarbonImmutable::parse('2026-06-01'))
        ->bookedFor(CarbonImmutable::parse('2026-06-01')));

    $ledger->post(TransactionDraft::make('acct-123', GenericPayload::make('deposit', [
        'amount' => 5,
    ]))
        ->withReason('weekly dep')
        ->occurredAt(CarbonImmutable::parse('2026-06-02'))
        ->bookedFor(CarbonImmutable::parse('2026-06-02')));

    $ledger->post(TransactionDraft::make('acct-123', GenericPayload::make('withdraw', [
        'amount' => -4,
    ]))
        ->withReason('atm withdrawal')
        ->occurredAt(CarbonImmutable::parse('2026-06-03'))
        ->bookedFor(CarbonImmutable::parse('2026-06-03')));

    $this->assertEquals(['total' => 2], $ledger->getAggregate('acct-123'));
});

it('voids transactions', function () {
    $ledger = new SimpleLedger();

    $time = CarbonImmutable::parse('2026-06-02');
    $this->travelTo($time);
    $this->actingAs(fakeUser());

    $ledger->post(TransactionDraft::make('acct-123', GenericPayload::make('deposit', [
        'amount' => 2,
    ]))
        ->withReason('weekly deposit')
        ->occurredAt(CarbonImmutable::parse('2026-06-01'))
        ->bookedFor(CarbonImmutable::parse('2026-06-01')));

    $transaction = $ledger->post(TransactionDraft::make('acct-123', GenericPayload::make('deposit', [
        'amount' => 2,
    ]))
        ->withReason('weekly deposit')
        ->occurredAt(CarbonImmutable::parse('2026-06-01'))
        ->bookedFor(CarbonImmutable::parse('2026-06-01')));

    $this->travel('1 day');


    $ledger->void(VoidDraft::make($transaction->id)
        ->withReason('check bounced'));

    $this->assertDatabaseCount('ledger_transaction', 3);

    $this->assertDatabaseHas('ledger_transaction', [
        'entered_by_user_id' => '89',
        'recorded_at' => '2026-06-02 00:00:00',
        'reverses_transaction_id' => null,
        'adjusts_transaction_id' => null,
        'payload_type' => 'deposit',
        'ledger_type' => 'cash-account',
        'ledger_id' => 'acct-123',
        'effective_at' => '2026-06-01 00:00:00',
        'reason' => 'weekly deposit',
    ]);

    $this->assertDatabaseHas('ledger_transaction', [
        'entered_by_user_id' => '89',
        'recorded_at' => '2026-06-02 00:00:00',
        'reverses_transaction_id' => $transaction->id,
        'adjusts_transaction_id' => null,
        'payload_type' => 'deposit',
        'ledger_type' => 'cash-account',
        'ledger_id' => 'acct-123',
        'effective_at' => '2026-06-02 00:00:00',
        'reason' => 'check bounced',
    ]);

    $this->assertEquals(['total' => 2], $ledger->getAggregate('acct-123'));
});

it('transfers between ledger ids', function () {
    $ledger = new SimpleLedger();

    $time = CarbonImmutable::parse('2026-06-02');
    $this->travelTo($time);
    $this->actingAs(fakeUser());

    $transaction = $ledger->post(TransactionDraft::make('acct-123', GenericPayload::make('deposit', [
        'amount' => 5,
    ]))
        ->withReason('weekly deposit')
        ->occurredAt(CarbonImmutable::parse('2026-06-01'))
        ->bookedFor(CarbonImmutable::parse('2026-06-01')));


    $sourceTransfer = GenericPayload::make('transfer', ['amount' => -2]);

    $transfer = $ledger->transfer($sourceTransfer, 'acct-123', 'acct-201', $time, 'transfer request');

    $this->assertDatabaseCount('ledger_transaction', 3);

    $this->assertDatabaseHas('ledger_transaction', [
        'entered_by_user_id' => '89',
        'recorded_at' => '2026-06-02 00:00:00',
        'reverses_transaction_id' => null,
        'adjusts_transaction_id' => null,
        'correlation_id' => null,
        'payload_type' => 'deposit',
        'ledger_type' => 'cash-account',
        'ledger_id' => 'acct-123',
        'effective_at' => '2026-06-01 00:00:00',
        'reason' => 'weekly deposit',
    ]);

    $this->assertDatabaseHas('ledger_transaction', [
        'entered_by_user_id' => '89',
        'recorded_at' => '2026-06-02 00:00:00',
        'reverses_transaction_id' => null,
        'adjusts_transaction_id' => null,
        'correlation_id' => $transfer->correlationId,
        'payload_type' => 'transfer',
        'ledger_type' => 'cash-account',
        'ledger_id' => 'acct-123',
        'effective_at' => '2026-06-02 00:00:00',
        'reason' => 'transfer request',
    ]);

    $this->assertDatabaseHas('ledger_transaction', [
        'entered_by_user_id' => '89',
        'recorded_at' => '2026-06-02 00:00:00',
        'reverses_transaction_id' => null,
        'adjusts_transaction_id' => null,
        'correlation_id' => $transfer->correlationId,
        'payload_type' => 'transfer',
        'ledger_type' => 'cash-account',
        'ledger_id' => 'acct-201',
        'effective_at' => '2026-06-02 00:00:00',
        'reason' => 'transfer request',
    ]);

    $this->assertEquals(['total' => 3], $ledger->getAggregate('acct-123'));
    $this->assertEquals(['total' => 2], $ledger->getAggregate('acct-201'));
});

test('stream version is zero when stream is empty', function () {
    $ledger = new SimpleLedger();
    $this->assertEquals(0, $ledger->streamVersion('234'));
});

test('stream version increments', function () {
    $ledger = new SimpleLedger();

    $transaction = $ledger->post(TransactionDraft::make('234', GenericPayload::make('deposit', [
        'amount' => 2,
    ]))
        ->authoredBy('usr-123')
        ->withReason('weekly deposit')
        ->occurredAt(CarbonImmutable::parse('2026-06-01'))
        ->bookedFor(CarbonImmutable::parse('2026-06-01')));

    $this->assertEquals(1, $ledger->streamVersion('234'));
});

function fakeUser(): User
{
    $user = new User();
    $user->forceFill(['id' => '89']);

    return $user;
}
