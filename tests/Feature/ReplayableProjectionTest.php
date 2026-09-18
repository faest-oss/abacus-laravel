<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Faest\Abacus\Contracts\DeserializablePayload;
use Faest\Abacus\Contracts\Ledger;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Contracts\ReplayableProjector;
use Faest\Abacus\Data\GenericPayload;
use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Facades\Abacus;
use Faest\Abacus\Models\LedgerOperation;
use Faest\Abacus\Models\LedgerTransaction;
use Faest\Abacus\Tests\Fixtures\SimpleLedger;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Abacus::registerLedger(new SimpleLedger);
    Abacus::registerLedger(new ReplayLedger);

    Schema::create('replay_projection', function (Blueprint $table): void {
        $table->id();
        $table->string('ledger_type');
        $table->string('transaction_id');
        $table->unsignedInteger('operation_position');
    });
});

it('resets and rebuilds only the selected ledger in canonical order across chunk sizes', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-02 12:00:00'));
    Abacus::postMany(
        'cash-account',
        'cash',
        [replayAmount(10), replayAmount(20)],
        replayContext(),
    );

    $this->travelTo(CarbonImmutable::parse('2026-01-01 12:00:00'));
    Abacus::post('cash-account', 'older', replayAmount(5), replayContext());
    Abacus::post('replay-account', 'other', replayAmount(7), replayContext());

    DB::table('replay_projection')->insert([
        [
            'ledger_type' => 'cash-account',
            'transaction_id' => 'stale-cash',
            'operation_position' => 0,
        ],
        [
            'ledger_type' => 'replay-account',
            'transaction_id' => 'keep-other',
            'operation_position' => 0,
        ],
    ]);

    $expected = LedgerTransaction::query()
        ->where('ledger_type', 'cash-account')
        ->orderBy('system_date')
        ->orderBy('operation_id')
        ->orderBy('operation_position')
        ->pluck('id')
        ->all();

    $processed = Abacus::rebuildProjection(new RecordingReplayableProjector, 'cash-account', 1);
    $smallChunks = replayedTransactionIds('cash-account');
    $largeProcessed = Abacus::rebuildProjection(RecordingReplayableProjector::class, SimpleLedger::class, 100);

    expect($processed)->toBe(3)
        ->and($largeProcessed)->toBe(3)
        ->and($smallChunks)->toBe($expected)
        ->and(replayedTransactionIds('cash-account'))->toBe($expected)
        ->and(replayedTransactionIds('replay-account'))->toBe(['keep-other']);
});

it('does not run replayable projection work during ordinary appends', function () {
    Abacus::post('cash-account', 'cash', replayAmount(10), replayContext());

    expect(DB::table('replay_projection')->count())->toBe(0);
});

it('restores the previous projection when reset or historical projection fails', function (bool $failReset) {
    Abacus::post('cash-account', 'cash', replayAmount(10), replayContext());
    seedExistingReplayProjection();

    $projector = new FailingReplayableProjector(
        failReset: $failReset,
        failHistorical: ! $failReset,
    );

    expect(fn () => Abacus::rebuildProjection($projector, 'cash-account', 1))
        ->toThrow(RuntimeException::class, 'replay failed')
        ->and(replayedTransactionIds('cash-account'))->toBe(['previous']);
})->with([true, false]);

it('restores the previous projection when typed payload hydration fails', function () {
    Abacus::post('replay-account', 'account', new UnhydratablePayload(10), replayContext());
    Abacus::registerPayload('test:unhydratable', UnhydratablePayload::class);
    seedExistingReplayProjection('replay-account');

    expect(fn () => Abacus::rebuildProjection(
        new RecordingReplayableProjector,
        'replay-account',
        1,
    ))->toThrow(RuntimeException::class, 'hydrate failed')
        ->and(replayedTransactionIds('replay-account'))->toBe(['previous']);
});

it('runs the rebuild command and reports the processed count', function () {
    Abacus::post('cash-account', 'cash', replayAmount(10), replayContext());

    $this->artisan('abacus:projection:rebuild', [
        'projector' => RecordingReplayableProjector::class,
        '--ledger' => 'cash-account',
        '--chunk' => '1',
    ])->expectsOutputToContain('Processed 1 transactions.')
        ->assertSuccessful();
});

it('validates rebuild command arguments', function (array $arguments, string $message) {
    expect(fn () => Artisan::call('abacus:projection:rebuild', $arguments))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'projector' => [[
        'projector' => SimpleLedger::class,
        '--ledger' => 'cash-account',
    ], 'ReplayableProjector'],
    'ledger' => [[
        'projector' => RecordingReplayableProjector::class,
    ], '--ledger'],
    'chunk' => [[
        'projector' => RecordingReplayableProjector::class,
        '--ledger' => 'cash-account',
        '--chunk' => 'zero',
    ], '--chunk'],
]);

it('requires confirmation in production and honors force', function () {
    $this->app->detectEnvironment(fn (): string => 'production');
    Abacus::post('cash-account', 'cash', replayAmount(10), replayContext());

    $this->artisan('abacus:projection:rebuild', [
        'projector' => RecordingReplayableProjector::class,
        '--ledger' => 'cash-account',
    ])->expectsConfirmation('Are you sure you want to run this command?', 'no')
        ->assertFailed();

    $this->artisan('abacus:projection:rebuild', [
        'projector' => RecordingReplayableProjector::class,
        '--ledger' => 'cash-account',
        '--force' => true,
    ])->assertSuccessful();
});

it('propagates the original projection error from the rebuild command', function () {
    expect(fn () => Artisan::call('abacus:projection:rebuild', [
        'projector' => AlwaysFailingReplayableProjector::class,
        '--ledger' => 'cash-account',
    ]))->toThrow(RuntimeException::class, 'original projection failure');
});

final class RecordingReplayableProjector implements ReplayableProjector
{
    public function resetProjection(string $ledgerType, Connection $connection): void
    {
        $connection->table('replay_projection')->where('ledger_type', $ledgerType)->delete();
    }

    public function projectHistorical(
        LedgerTransaction $transaction,
        LedgerPayload $payload,
        LedgerOperation $operation,
        Connection $connection,
    ): void {
        $connection->table('replay_projection')->insert([
            'ledger_type' => $transaction->ledger_type,
            'transaction_id' => $transaction->id,
            'operation_position' => $transaction->operation_position,
        ]);
    }
}

final class FailingReplayableProjector implements ReplayableProjector
{
    public function __construct(
        private bool $failReset,
        private bool $failHistorical,
    ) {}

    public function resetProjection(string $ledgerType, Connection $connection): void
    {
        $connection->table('replay_projection')->where('ledger_type', $ledgerType)->delete();

        if ($this->failReset) {
            throw new RuntimeException('replay failed');
        }
    }

    public function projectHistorical(
        LedgerTransaction $transaction,
        LedgerPayload $payload,
        LedgerOperation $operation,
        Connection $connection,
    ): void {
        if ($this->failHistorical) {
            throw new RuntimeException('replay failed');
        }
    }
}

final class AlwaysFailingReplayableProjector implements ReplayableProjector
{
    public function resetProjection(string $ledgerType, Connection $connection): void
    {
        throw new RuntimeException('original projection failure');
    }

    public function projectHistorical(
        LedgerTransaction $transaction,
        LedgerPayload $payload,
        LedgerOperation $operation,
        Connection $connection,
    ): void {}
}

final class ReplayLedger implements Ledger
{
    public function getLedgerType(): string
    {
        return 'replay-account';
    }

    /** @return array{total: int} */
    public function initializeAggregate(): array
    {
        return ['total' => 0];
    }

    /** @param array{total: int}|JsonSerializable $existingAggregate */
    public function applyToAggregate(LedgerPayload $payload, array|JsonSerializable $existingAggregate): array
    {
        if (! is_array($existingAggregate)) {
            throw new InvalidArgumentException('Unsupported aggregate.');
        }

        return ['total' => $existingAggregate['total'] + $payload->jsonSerialize()['amount']];
    }

    public function assertValidPayload(LedgerPayload $payload): void {}

    public function assertAggregateInvariants(array|JsonSerializable $aggregate): void {}

    public function computeOpposing(LedgerPayload $payload): LedgerPayload
    {
        return GenericPayload::make($payload->payloadType(), [
            'amount' => -$payload->jsonSerialize()['amount'],
        ]);
    }
}

final readonly class UnhydratablePayload implements DeserializablePayload
{
    public function __construct(private int $amount) {}

    public function payloadType(): string
    {
        return 'test:unhydratable';
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->amount];
    }

    /** @param array<string, mixed> $data */
    public static function fromPayload(array $data): static
    {
        throw new RuntimeException('hydrate failed');
    }
}

/** @return list<string> */
function replayedTransactionIds(string $ledgerType): array
{
    return DB::table('replay_projection')
        ->where('ledger_type', $ledgerType)
        ->orderBy('id')
        ->pluck('transaction_id')
        ->all();
}

function seedExistingReplayProjection(string $ledgerType = 'cash-account'): void
{
    DB::table('replay_projection')->insert([
        'ledger_type' => $ledgerType,
        'transaction_id' => 'previous',
        'operation_position' => 0,
    ]);
}

function replayAmount(int $amount): GenericPayload
{
    return GenericPayload::make('entry', ['amount' => $amount]);
}

function replayContext(): PostingContext
{
    return PostingContext::forProcess(
        'replay-test',
        now(),
        now(),
        'test replay',
    );
}
