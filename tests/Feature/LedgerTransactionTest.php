<?php

declare(strict_types=1);

use Faest\Abacus\Exceptions\LedgerImmutableException;
use Faest\Abacus\Models\LedgerTransaction;
use Illuminate\Support\Carbon;

function createLedgerTransaction(): LedgerTransaction
{
    $transaction = new LedgerTransaction();
    $transaction->ledger_type = 'utility-billing';
    $transaction->ledger_id = 'account-123';
    $transaction->payload = json_encode(['type' => 'charge', 'amount' => 1250], JSON_THROW_ON_ERROR);
    $transaction->payload_type = 'charge';
    $transaction->effective_at = Carbon::parse('2026-01-15 09:30:00');
    $transaction->recorded_at = Carbon::parse('2026-01-15 10:00:00');
    $transaction->accounting_date = Carbon::parse('2026-01-15 10:00:00');
    $transaction->entered_by_user_id = 'user-456';
    $transaction->reason = 'testing';
    $transaction->stream_version = 1;
    $transaction->save();

    return $transaction;
}

it('persists a ledger transaction with a ULID and no updated timestamp', function () {
    $transaction = createLedgerTransaction();

    expect($transaction)
        ->id->toMatch('/^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$/')
        ->updated_at->toBeNull();

    $this->assertDatabaseHas('ledger_transaction', [
        'id' => $transaction->id,
        'ledger_type' => 'utility-billing',
        'ledger_id' => 'account-123',
    ]);
});

it('does not retain records created by an earlier test', function () {
    $this->assertDatabaseCount('ledger_transaction', 0);
});

it('prevents a ledger transaction from being updated', function () {
    $transaction = createLedgerTransaction();

    $transaction->ledger_id = 'account-789';

    expect(fn() => $transaction->save())
        ->toThrow(LedgerImmutableException::class, 'Ledger entries cannot be modified.');
});

it('prevents a ledger transaction from being deleted', function () {
    $transaction = createLedgerTransaction();

    expect(fn() => $transaction->delete())
        ->toThrow(LedgerImmutableException::class, 'Ledger entries cannot be deleted.');
});
