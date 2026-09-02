<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Faest\Abacus\Data\GenericPayload;
use Faest\Abacus\Tests\Fixtures\SimpleLedger;
use Workbench\App\Models\User;

it('posts transactions', function () {
    $ledger = new SimpleLedger;

    $this->assertDatabaseCount('ledger_transaction', 0);
    $time = CarbonImmutable::parse('2026-06-02');
    $this->travelTo($time);

    $this->actingAs(fakeUser());

    $ledger->post('acct-123', GenericPayload::make('deposit', [
        'amount' => 1,
    ]), 'weekly deposit', CarbonImmutable::parse('2026-06-01'));

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

it('enforces domain invariants', function () {
    $ledger = new SimpleLedger;

    $this->assertDatabaseCount('ledger_transaction', 0);
    $time = CarbonImmutable::parse('2026-06-02');
    $this->travelTo($time);

    $this->actingAs(fakeUser());

    $ledger->post('acct-123', GenericPayload::make('deposit', [
        'amount' => 1,
    ]), 'weekly deposit', CarbonImmutable::parse('2026-06-01'));

    $overdraft = fn () => $ledger->post('acct-123', GenericPayload::make('withdraw', [
        'amount' => -2,
    ]), 'weekly deposit', CarbonImmutable::parse('2026-06-02'));

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
    $ledger = new SimpleLedger;

    $this->actingAs(fakeUser());

    $ledger->post('acct-123', GenericPayload::make('deposit', [
        'amount' => 1,
    ]), 'weekly deposit', CarbonImmutable::parse('2026-06-01'));

    $ledger->post('acct-123', GenericPayload::make('deposit', [
        'amount' => 5,
    ]), 'weekly deposit', CarbonImmutable::parse('2026-06-02'));

    $ledger->post('acct-123', GenericPayload::make('withdraw', [
        'amount' => -4,
    ]), 'weekly deposit', CarbonImmutable::parse('2026-06-02'));

    $this->assertEquals(['total' => 2], $ledger->getAggregate('acct-123'));
});

it('voids transactions', function () {
    $ledger = new SimpleLedger;

    $time = CarbonImmutable::parse('2026-06-02');
    $this->travelTo($time);
    $this->actingAs(fakeUser());

    $ledger->post('acct-123', GenericPayload::make('deposit', [
        'amount' => 2,
        'type' => 'deposit',
    ]), 'weekly deposit', CarbonImmutable::parse('2026-06-01'));

    $transaction = $ledger->post('acct-123', GenericPayload::make('deposit', [
        'amount' => 1,
        'type' => 'deposit',
    ]), 'weekly deposit', CarbonImmutable::parse('2026-06-01'));

    $this->travel('1 day');

    $ledger->void($transaction->id, 'check bounced');

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
        'effective_at' => '2026-06-01 00:00:00',
        'reason' => 'check bounced',
    ]);

    $this->assertEquals(['total' => 2], $ledger->getAggregate('acct-123'));
});

it('transfers between ledger ids', function () {
    $ledger = new SimpleLedger;

    $time = CarbonImmutable::parse('2026-06-02');
    $this->travelTo($time);
    $this->actingAs(fakeUser());

    $transaction = $ledger->post('acct-123', GenericPayload::make('deposit', [
        'amount' => 5,
        'type' => 'deposit',
    ]), 'weekly deposit', CarbonImmutable::parse('2026-06-01'));

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

function fakeUser(): User
{
    $user = new User;
    $user->forceFill(['id' => '89']);

    return $user;
}
