<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Data\GenericPayload;
use Faest\Abacus\Data\TransactionDraft;
use Faest\Abacus\Data\VoidDraft;
use Faest\Abacus\Exceptions\UnexpectedStreamVersionException;
use Faest\Abacus\Tests\Fixtures\SimpleLedger;
use Workbench\App\Models\User;

it('posts transactions', function () {
    $ledger = new SimpleLedger;

    $this->assertDatabaseCount('ledger_transaction', 0);
    $time = CarbonImmutable::parse('2026-06-02');
    $this->travelTo($time);

    $this->actingAs(fakeUser());

    $draft = standardTransaction();
    $result = $ledger->post($draft);

    $this->assertEquals(1, $result->version);
    $this->assertDatabaseCount('ledger_transaction', 1);
    $this->assertDatabaseHas('ledger_transaction', standardDbRecord([
        'id' => $result->id,
    ]));
});

it('enforces domain invariants', function () {
    $ledger = new SimpleLedger;

    $this->assertDatabaseCount('ledger_transaction', 0);
    $time = CarbonImmutable::parse('2026-06-02');
    $this->travelTo($time);
    $this->assertEquals(0, $ledger->streamVersion(standardLedgerId()));

    $overdraft = fn () => $ledger->post(standardTransaction(
        payload: standardPayload('withdraw', ['amount' => -1])),
    );

    expect($overdraft)
        ->toThrow(Exception::class, 'Cannot be negative');

    $this->assertEquals(0, $ledger->streamVersion(standardLedgerId()));

    $this->assertDatabaseCount('ledger_transaction', 0);
    $this->assertDatabaseCount('ledger_stream_head', 1);
});

it('calculates aggregates', function () {
    $ledger = new SimpleLedger;

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
    $ledger = new SimpleLedger;

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
    $ledger = new SimpleLedger;

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
    $ledger = new SimpleLedger;
    $this->assertEquals(0, $ledger->streamVersion('234'));
});

test('stream version increments', function () {
    $ledger = new SimpleLedger;

    $transaction = $ledger->post(TransactionDraft::make('234', GenericPayload::make('deposit', [
        'amount' => 2,
    ]))
        ->authoredBy('usr-123')
        ->withReason('weekly deposit')
        ->occurredAt(CarbonImmutable::parse('2026-06-01'))
        ->bookedFor(CarbonImmutable::parse('2026-06-01')));

    $this->assertEquals(1, $ledger->streamVersion('234'));
});

test('locking on version zero works when ledger is empty', function () {
    $ledger = new SimpleLedger;
    $time = CarbonImmutable::parse('2026-06-02');
    $this->travelTo($time);

    $this->assertEquals(0, $ledger->streamVersion('234'));

    $transaction = $ledger->post(standardTransaction()
        ->failIfVersionIsnt(0));

    $this->assertDatabaseHas('ledger_transaction', standardDbRecord());
});

test('locking on version zero fails when ledger is not empty', function () {
    $ledger = new SimpleLedger;
    $time = CarbonImmutable::parse('2026-06-02');
    $this->travelTo($time);

    $this->assertEquals(0, $ledger->streamVersion('234'));

    $ledger->post(standardTransaction());

    $secondPost = fn () => $ledger->post(standardTransaction()
        ->failIfVersionIsnt(0));

    expect($secondPost)->toThrow(function (UnexpectedStreamVersionException $e) {
        expect($e->getMessage())->toBe('Stream expectation failed');
        expect($e->ledgerType)->toBe('cash-account');
        expect($e->ledgerId)->toBe('234');
        expect($e->expectedVersion)->toBe(0);
        expect($e->actualVersion)->toBe(1);
    });

    $this->assertDatabaseCount('ledger_transaction', 1);

    $this->assertDatabaseHas('ledger_transaction', standardDbRecord());
});

function standardLedgerId(): string
{
    return '234';
}

function standardTransaction(string $ledgerId = '234', ?LedgerPayload $payload = null): TransactionDraft
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
function standardPayload(
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
function standardDbRecord(array $record = []): array
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

function fakeUser(): User
{
    $user = new User;
    $user->forceFill(['id' => '89']);

    return $user;
}
