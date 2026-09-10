<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Faest\Abacus\BundleBuilder;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Data\GenericPayload;
use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Data\TransactionDraft;
use Faest\Abacus\Data\VoidDraft;
use Faest\Abacus\Exceptions\FailedInvariantException;
use Faest\Abacus\Exceptions\UnexpectedStreamVersionException;
use Faest\Abacus\Facades\Abacus;
use Faest\Abacus\Tests\Fixtures\SimpleLedger;
use Faest\Abacus\Tests\TestCase;
use Illuminate\Support\Str;
use Workbench\App\Models\User;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function PHPUnit\Framework\assertEquals;

beforeEach(function () {
    Abacus::registerLedger(new SimpleLedger);
});

it('posts transactions', function () {
    /** @var TestCase $this */
    assertDatabaseCount('ledger_transaction', 0);
    $time = CarbonImmutable::parse('2026-06-02');
    $this->travelTo($time);

    $draft = standardTransaction();
    $result = Abacus::post($draft, standardContext());

    $this->assertEquals(1, $result->version);
    assertDatabaseCount('ledger_transaction', 1);
    assertDatabaseHas('ledger_transaction', standardDbRecord([
        'id' => $result->id,
    ]));
});

it('posts multiple transactions', function () {
    /** @var TestCase $this */
    assertDatabaseCount('ledger_transaction', 0);
    $time = CarbonImmutable::parse('2026-06-02');
    $this->travelTo($time);

    $draft = standardTransaction(payload: standardPayload(payload: ['amount' => 1]));
    $draft2 = standardTransaction(payload: standardPayload(payload: ['amount' => -1]));
    $correlationId = Str::uuid()->toString();
    $results = Abacus::postMany([$draft, $draft2], standardContext()->withCorrelationId($correlationId));

    $this->assertEquals(2, count($results));
    $this->assertNotEquals($results[0]->id, $results[1]->id);
    $this->assertEquals(1, $results[0]->version);
    $this->assertEquals(2, $results[1]->version);
    assertDatabaseCount('ledger_transaction', 2);
    assertDatabaseHas('ledger_transaction', standardDbRecord([
        'id' => $results[0]->id,
        'reason' => 'paycheck deposit',
        'correlation_id' => $correlationId,
    ]));

    assertDatabaseHas('ledger_transaction', standardDbRecord([
        'id' => $results[1]->id,
        'reason' => 'paycheck deposit',
        'stream_version' => 2,
        'correlation_id' => $correlationId,
    ]));
});

it('enforces domain invariants', function () {
    /** @var TestCase $this */
    assertDatabaseCount('ledger_transaction', 0);
    $time = CarbonImmutable::parse('2026-06-02');
    $this->travelTo($time);
    $this->assertEquals(0, Abacus::streamVersion('cash-account', standardLedgerId()));

    $overdraft = fn () => Abacus::post(
        standardTransaction(
            payload: standardPayload('withdraw', ['amount' => -1]),
        ),
        standardContext(),
    );

    expect($overdraft)
        ->toThrow(Exception::class, 'Cannot be negative');

    $this->assertEquals(0, Abacus::streamVersion('cash-account', standardLedgerId()));

    assertDatabaseCount('ledger_transaction', 0);
    assertDatabaseCount('ledger_stream_head', 1);
});

it('posts multiple transactions - all of or none of', function () {
    /** @var TestCase $this */
    assertDatabaseCount('ledger_transaction', 0);
    $time = CarbonImmutable::parse('2026-06-02');
    $this->travelTo($time);

    $draft = standardTransaction();
    $draft2 = standardTransaction(payload: standardPayload('withdraw', ['amount' => -500]));
    expect(fn () => Abacus::postMany([$draft, $draft2], standardContext()))->toThrow(function (FailedInvariantException $e) use ($draft2) {
        assertEquals($draft2, $e->getFailedDraft());
    });

    assertDatabaseCount('ledger_transaction', 0);
});

it('calculates aggregates', function () {
    /** @var TestCase $this */
    $this->actingAs(fakeUser());

    Abacus::post(
        TransactionDraft::make('cash-account', 'acct-123', GenericPayload::make('deposit', [
            'amount' => 1,
        ])),
        standardContext(),
    );

    Abacus::post(
        TransactionDraft::make('cash-account', 'acct-123', GenericPayload::make('deposit', [
            'amount' => 5,
        ])),
        standardContext(),
    );

    Abacus::post(
        TransactionDraft::make('cash-account', 'acct-123', GenericPayload::make('withdraw', [
            'amount' => -4,
        ])),
        standardContext()->withReason('withdrawal'),
    );

    $this->assertEquals(['total' => 2], Abacus::getAggregate('cash-account', 'acct-123'));
});

it('voids transactions', function () {
    /** @var TestCase $this */
    $time = CarbonImmutable::parse('2026-06-02');
    $this->travelTo($time);

    Abacus::post(
        TransactionDraft::make('cash-account', 'acct-123', GenericPayload::make('deposit', [
            'amount' => 2,
        ])),
        standardContext(),
    );

    $transaction = Abacus::post(
        TransactionDraft::make('cash-account', 'acct-123', GenericPayload::make('deposit', [
            'amount' => 2,
        ])),
        standardContext(),
    );

    $this->travel('1 day');

    Abacus::void(
        VoidDraft::make($transaction->id),
        standardContext()->withReason('check bounced')->withEventDate(CarbonImmutable::parse('2026-06-02')),
    );

    assertDatabaseCount('ledger_transaction', 3);

    assertDatabaseHas('ledger_transaction', [
        'entered_by_user_id' => 'user:usr-123',
        'recorded_at' => '2026-06-02 00:00:00',
        'reverses_transaction_id' => null,
        'adjusts_transaction_id' => null,
        'payload_type' => 'deposit',
        'ledger_type' => 'cash-account',
        'ledger_id' => 'acct-123',
        'effective_at' => '2026-06-01 00:00:00',
        'reason' => 'paycheck deposit',
    ]);

    assertDatabaseHas('ledger_transaction', [
        'entered_by_user_id' => 'user:usr-123',
        'recorded_at' => '2026-06-02 00:00:00',
        'reverses_transaction_id' => $transaction->id,
        'adjusts_transaction_id' => null,
        'payload_type' => 'deposit',
        'ledger_type' => 'cash-account',
        'ledger_id' => 'acct-123',
        'effective_at' => '2026-06-02 00:00:00',
        'reason' => 'check bounced',
    ]);

    $this->assertEquals(['total' => 2], Abacus::getAggregate('cash-account', 'acct-123'));
});

it('transfers between ledger ids', function () {
    /** @var TestCase $this */
    $time = CarbonImmutable::parse('2026-06-02');
    $this->travelTo($time);
    $this->actingAs(fakeUser());

    Abacus::post(
        TransactionDraft::make('cash-account', 'acct-123', GenericPayload::make('deposit', [
            'amount' => 5,
        ])),
        standardContext()->withReason('weekly deposit'),
    );

    $sourceTransfer = GenericPayload::make('transfer', ['amount' => -2]);

    $transfer = Abacus::transfer(
        payload: $sourceTransfer,
        sourceLedgerId: 'acct-123',
        destinationLedgerId: 'acct-201',
        ledgerType: 'cash-account',
        context: standardContext()
            ->withReason('transfer request')
            ->withEventDate(CarbonImmutable::parse('2026-06-02')),
    );

    assertDatabaseCount('ledger_transaction', 3);

    assertDatabaseHas('ledger_transaction', [
        'entered_by_user_id' => 'user:usr-123',
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

    assertDatabaseHas('ledger_transaction', [
        'entered_by_user_id' => 'user:usr-123',
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

    assertDatabaseHas('ledger_transaction', [
        'entered_by_user_id' => 'user:usr-123',
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

    $this->assertEquals(['total' => 3], Abacus::getAggregate('cash-account', 'acct-123'));
    $this->assertEquals(['total' => 2], Abacus::getAggregate('cash-account', 'acct-201'));
});

it('supports bundle builder staging across streams', function () {
    /** @var TestCase $this */
    $context = standardContext()->withReason('monthly bundle');

    $results = Abacus::bundle($context, function (BundleBuilder $bundle) {
        $bundle->post('cash-account', 'acct-1', GenericPayload::make('deposit', ['amount' => 10]));
        $bundle->post('cash-account', 'acct-2', GenericPayload::make('deposit', ['amount' => 20]));
    });

    $this->assertCount(2, $results);
    assertDatabaseCount('ledger_transaction', 2);
    $this->assertEquals(['total' => 10], Abacus::getAggregate('cash-account', 'acct-1'));
    $this->assertEquals(['total' => 20], Abacus::getAggregate('cash-account', 'acct-2'));
});

it('supports reverseOperation for cascading reversals', function () {
    /** @var TestCase $this */
    Abacus::post(
        TransactionDraft::make('cash-account', 'acct-source', GenericPayload::make('deposit', ['amount' => 50])),
        standardContext(),
    );
    Abacus::post(
        TransactionDraft::make('cash-account', 'acct-dest', GenericPayload::make('deposit', ['amount' => 50])),
        standardContext(),
    );

    $transfer = Abacus::transfer(
        payload: GenericPayload::make('transfer', ['amount' => -15]),
        sourceLedgerId: 'acct-source',
        destinationLedgerId: 'acct-dest',
        ledgerType: 'cash-account',
        context: standardContext()->withReason('initial transfer'),
    );

    assertDatabaseCount('ledger_transaction', 4);
    $this->assertEquals(['total' => 35], Abacus::getAggregate('cash-account', 'acct-source'));
    $this->assertEquals(['total' => 65], Abacus::getAggregate('cash-account', 'acct-dest'));

    // Reverse the whole operation by correlation ID
    $reversals = Abacus::reverseOperation($transfer->correlationId, standardContext()->withReason('reversing transfer'));

    $this->assertCount(2, $reversals);
    assertDatabaseCount('ledger_transaction', 6);
    $this->assertEquals(['total' => 50], Abacus::getAggregate('cash-account', 'acct-source'));
    $this->assertEquals(['total' => 50], Abacus::getAggregate('cash-account', 'acct-dest'));
});

it('rejects negative expected version ids', function () {
    expect(fn () => Abacus::post(standardTransaction()->failIfVersionIsnt(-1)))->toThrow(InvalidArgumentException::class);
    assertDatabaseCount('ledger_transaction', 0);
});

test('stream version is zero when stream is empty', function () {
    /** @var TestCase $this */
    $this->assertEquals(0, Abacus::streamVersion('cash-account', '234'));
});

test('stream version increments', function () {
    /** @var TestCase $this */
    Abacus::post(
        TransactionDraft::make('cash-account', '234', GenericPayload::make('deposit', [
            'amount' => 2,
        ])),
        standardContext(),
    );

    $this->assertEquals(1, Abacus::streamVersion('cash-account', '234'));
});

test('locking on version zero works when ledger is empty', function () {
    /** @var TestCase $this */
    $time = CarbonImmutable::parse('2026-06-02');
    $this->travelTo($time);

    $this->assertEquals(0, Abacus::streamVersion('cash-account', '234'));

    Abacus::post(standardTransaction()
        ->failIfVersionIsnt(0), standardContext()->withReason('paycheck deposit'));

    assertDatabaseHas('ledger_transaction', standardDbRecord());
});

test('locking on version zero fails when ledger is not empty', function () {
    /** @var TestCase $this */
    $time = CarbonImmutable::parse('2026-06-02');
    $this->travelTo($time);

    $this->assertEquals(0, Abacus::streamVersion('cash-account', '234'));

    Abacus::post(standardTransaction(), standardContext()->withReason('paycheck deposit'));

    $secondPost = fn () => Abacus::post(standardTransaction()
        ->failIfVersionIsnt(0), standardContext());

    expect($secondPost)->toThrow(function (UnexpectedStreamVersionException $e) {
        expect($e->getMessage())->toBe('Stream expectation failed');
        expect($e->ledgerType)->toBe('cash-account');
        expect($e->ledgerId)->toBe('234');
        expect($e->expectedVersion)->toBe(0);
        expect($e->actualVersion)->toBe(1);
    });

    assertDatabaseCount('ledger_transaction', 1);

    assertDatabaseHas('ledger_transaction', standardDbRecord());
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

    return TransactionDraft::make('cash-account', $ledgerId, $payload);
}

function standardContext(): PostingContext
{
    return PostingContext::forUser('usr-123', CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-01'), 'paycheck deposit');
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
            'entered_by_user_id' => 'user:usr-123',
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
