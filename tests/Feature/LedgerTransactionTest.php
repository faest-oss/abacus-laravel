<?php

declare(strict_types=1);

use Faest\Abacus\Enums\OperationKind;
use Faest\Abacus\Exceptions\LedgerImmutableException;
use Faest\Abacus\Models\LedgerOperation;
use Faest\Abacus\Models\LedgerTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function createLedgerTransaction(bool $createStreamHead = true): LedgerTransaction
{
    if ($createStreamHead) {
        DB::table('ledger_stream_head')->insertOrIgnore([
            'ledger_type' => 'utility-billing',
            'ledger_id' => 'account-123',
        ]);
    }

    $operation = createLedgerOperation();

    $transaction = new LedgerTransaction;
    $transaction->operation_id = $operation->id;
    $transaction->operation_position = 1;
    $transaction->ledger_type = 'utility-billing';
    $transaction->ledger_id = 'account-123';
    $transaction->payload = json_encode(['type' => 'charge', 'amount' => 1250], JSON_THROW_ON_ERROR);
    $transaction->payload_type = 'charge';
    $transaction->event_date = Carbon::parse('2026-01-15 09:30:00');
    $transaction->system_date = Carbon::parse('2026-01-15 10:00:00');
    $transaction->accounting_date = Carbon::parse('2026-01-15 10:00:00');
    $transaction->actor = 'user-456';
    $transaction->reason = 'testing';
    $transaction->stream_version = 1;
    $transaction->save();

    return $transaction;
}

function createLedgerOperation(): LedgerOperation
{
    $operation = new LedgerOperation;
    $operation->kind = OperationKind::Posting;
    $operation->actor = 'user-456';
    $operation->reason = 'testing';
    $operation->event_date = Carbon::parse('2026-01-15 09:30:00');
    $operation->accounting_date = Carbon::parse('2026-01-15 10:00:00');
    $operation->system_date = Carbon::parse('2026-01-15 10:00:00');
    $operation->metadata = [];
    $operation->request_fingerprint_version = 1;
    $operation->request_fingerprint = str_repeat('a', 64);
    $operation->save();

    return $operation;
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

it('requires a stream head for every ledger transaction', function () {
    // Roll back expected constraint violations without aborting the test transaction.
    expect(fn () => DB::transaction(fn () => createLedgerTransaction(createStreamHead: false)))
        ->toThrow(QueryException::class);

    $this->assertDatabaseCount('ledger_transaction', 0);
});

it('does not allow a stream head with transactions to be deleted', function () {
    createLedgerTransaction();

    expect(fn () => DB::transaction(fn () => DB::table('ledger_stream_head')->where([
        'ledger_type' => 'utility-billing',
        'ledger_id' => 'account-123',
    ])->delete()))->toThrow(QueryException::class);

    $this->assertDatabaseHas('ledger_stream_head', [
        'ledger_type' => 'utility-billing',
        'ledger_id' => 'account-123',
    ]);
    $this->assertDatabaseCount('ledger_transaction', 1);
});

it('prevents a ledger transaction from being updated', function () {
    $transaction = createLedgerTransaction();

    $transaction->ledger_id = 'account-789';

    expect(fn () => $transaction->save())
        ->toThrow(LedgerImmutableException::class, 'Ledger entries cannot be modified.');
});

it('prevents a ledger transaction from being deleted', function () {
    $transaction = createLedgerTransaction();

    expect(fn () => $transaction->delete())
        ->toThrow(LedgerImmutableException::class, 'Ledger entries cannot be deleted.');
});

it('prevents a ledger operation from being updated or deleted', function () {
    $operation = createLedgerOperation();
    $operation->reason = 'changed';

    expect(fn () => $operation->save())
        ->toThrow(LedgerImmutableException::class, 'Ledger operations cannot be modified.');

    $operation->refresh();

    expect(fn () => $operation->delete())
        ->toThrow(LedgerImmutableException::class, 'Ledger operations cannot be deleted.');
});
