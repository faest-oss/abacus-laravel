<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Faest\Abacus\Tests\Fixtures\SimpleLedger;
use Workbench\App\Models\User;

it('posts transactions', function () {
    $ledger = new SimpleLedger;

    $this->assertDatabaseCount('ledger_transaction', 0);
    $time = CarbonImmutable::parse('2026-06-02');
    $this->travelTo($time);

    $this->actingAs(fakeUser());

    $ledger->post([
        'amount' => 1,
        'type' => 'deposit',
        'account_id' => '123',
    ], 'weekly deposit', CarbonImmutable::parse('2026-06-01'));

    $this->assertDatabaseCount('ledger_transaction', 1);

    $this->assertDatabaseHas('ledger_transaction', [
        'entered_by_user_id' => '89',
        'recorded_at' => '2026-06-02 00:00:00',
        'reverses_transaction_id' => null,
        'adjusts_transaction_id' => null,
        'payload_type' => 'deposit',
        'ledger_type' => 'cash-account',
        'ledger_id' => '123',
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

    $ledger->post([
        'amount' => 1,
        'type' => 'deposit',
        'account_id' => '123',
    ], 'weekly deposit', CarbonImmutable::parse('2026-06-01'));

    $overdraft = fn () => $ledger->post([
        'amount' => -2,
        'type' => 'withdraw',
        'account_id' => '123',
    ], 'weekly deposit', CarbonImmutable::parse('2026-06-02'));

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
        'ledger_id' => '123',
        'effective_at' => '2026-06-01 00:00:00',
        'reason' => 'weekly deposit',
    ]);
});

it('calculates aggregates', function () {
    $ledger = new SimpleLedger;

    $this->actingAs(fakeUser());

    $ledger->post([
        'amount' => 1,
        'type' => 'deposit',
        'account_id' => '123',
    ], 'weekly deposit', CarbonImmutable::parse('2026-06-01'));

    $ledger->post([
        'amount' => 5,
        'type' => 'deposit',
        'account_id' => '123',
    ], 'weekly deposit', CarbonImmutable::parse('2026-06-02'));

    $ledger->post([
        'amount' => -4,
        'type' => 'withdraw',
        'account_id' => '123',
    ], 'weekly deposit', CarbonImmutable::parse('2026-06-02'));

    $this->assertEquals(['total' => 2], $ledger->getAggregate('123'));
});

it('voids transactions', function () {
    $ledger = new SimpleLedger;

    $time = CarbonImmutable::parse('2026-06-02');
    $this->travelTo($time);
    $this->actingAs(fakeUser());

    $transaction = $ledger->post([
        'amount' => 1,
        'type' => 'deposit',
        'account_id' => '123',
    ], 'weekly deposit', CarbonImmutable::parse('2026-06-01'));

    $this->travel('1 day');

    $ledger->void($transaction->id, 'check bounced');

    $this->assertDatabaseCount('ledger_transaction', 2);

    $this->assertDatabaseHas('ledger_transaction', [
        'entered_by_user_id' => '89',
        'recorded_at' => '2026-06-02 00:00:00',
        'reverses_transaction_id' => null,
        'adjusts_transaction_id' => null,
        'payload_type' => 'deposit',
        'ledger_type' => 'cash-account',
        'ledger_id' => '123',
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
        'ledger_id' => '123',
        'effective_at' => '2026-06-01 00:00:00',
        'reason' => 'check bounced',
    ]);

    $this->assertEquals(['total' => 0], $ledger->getAggregate('123'));
});

function fakeUser(): User
{
    $user = new User;
    $user->forceFill(['id' => '89']);

    return $user;
}
