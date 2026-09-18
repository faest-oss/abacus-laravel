<?php

declare(strict_types=1);

use Faest\Abacus\Abacus as AbacusCoordinator;
use Faest\Abacus\Contracts\HasProjectors;
use Faest\Abacus\Contracts\Ledger;
use Faest\Abacus\Contracts\LedgerPayload;
use Faest\Abacus\Contracts\OperationProjector;
use Faest\Abacus\Contracts\Projector;
use Faest\Abacus\Data\GenericPayload;
use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Facades\Abacus;
use Faest\Abacus\Models\LedgerOperation;
use Faest\Abacus\Models\LedgerTransaction;
use Faest\Abacus\OperationBuilder;
use Faest\Abacus\PayloadRegistry;
use Faest\Abacus\Tests\Fixtures\SimpleLedger;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\assertDatabaseCount;

beforeEach(function () {
    Abacus::registerLedger(new SimpleLedger);
    RecordingEntryProjector::$calls = [];
    RecordingOperationProjector::$calls = [];

    Schema::create('required_projection', function (Blueprint $table): void {
        $table->id();
        $table->string('transaction_id');
    });
});

it('runs entry and operation projectors after every entry and final head are visible', function () {
    Abacus::registerProjector('cash-account', RecordingEntryProjector::class)
        ->registerProjector('cash-account', new RecordingEntryProjector)
        ->registerOperationProjector('cash-account', RecordingOperationProjector::class);

    $result = Abacus::operation(projectionContext(), function (OperationBuilder $operation): void {
        $operation->post('cash-account', 'one', projectionAmount(10));
        $operation->post('cash-account', 'one', projectionAmount(20));
    });
    $connectionName = (string) config('database.default');

    expect(RecordingEntryProjector::$calls)->toBe([
        [1, 2, $connectionName],
        [2, 2, $connectionName],
    ])->and(RecordingOperationProjector::$calls)->toBe([[
        $result->id,
        [1, 2],
        ['cash-account', 'cash-account'],
    ]]);
});

it('combines declared and explicit registrations without duplicate invocation', function () {
    Abacus::registerLedger(new ProjectingLedger)
        ->registerProjector(ProjectingLedger::class, RecordingEntryProjector::class)
        ->registerOperationProjector('cash-account', RecordingOperationProjector::class)
        ->registerOperationProjector('projecting-account', RecordingOperationProjector::class);

    Abacus::operation(projectionContext(), function (OperationBuilder $operation): void {
        $operation->post('cash-account', 'cash', projectionAmount(10));
        $operation->post('projecting-account', 'projected', projectionAmount(20));
    });

    expect(RecordingEntryProjector::$calls)->toHaveCount(1)
        ->and(RecordingOperationProjector::$calls)->toHaveCount(1)
        ->and(RecordingOperationProjector::$calls[0][1])->toBe([1, 2]);
});

it('does not rerun required projectors for an idempotent operation replay', function () {
    Abacus::registerProjector('cash-account', new DatabaseRequiredProjector);
    $context = projectionContext()->withIdempotencyKey('projection:once');

    $first = Abacus::post('cash-account', 'one', projectionAmount(10), $context);
    $replayed = Abacus::post('cash-account', 'one', projectionAmount(10), $context);

    expect($replayed)->toEqual($first);
    assertDatabaseCount('required_projection', 1);
});

it('rolls back the complete operation when a required projector fails', function () {
    Abacus::registerProjector('cash-account', new DatabaseRequiredProjector(throws: true));

    expect(fn () => Abacus::post('cash-account', 'one', projectionAmount(10), projectionContext()))
        ->toThrow(RuntimeException::class, 'required projection failed');

    assertDatabaseCount('ledger_operation', 0);
    assertDatabaseCount('ledger_transaction', 0);
    assertDatabaseCount('ledger_stream_head', 0);
    assertDatabaseCount('required_projection', 0);
});

it('uses the selected connection for ledger and projection rollback', function () {
    config()->set('database.connections.projection_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    $this->artisan('migrate', [
        '--database' => 'projection_test',
        '--path' => realpath(__DIR__.'/../../database/migrations'),
        '--realpath' => true,
    ])->assertSuccessful();

    Schema::connection('projection_test')->create('required_projection', function (Blueprint $table): void {
        $table->id();
        $table->string('transaction_id');
    });

    $abacus = (new AbacusCoordinator(new PayloadRegistry))
        ->registerLedger(new SimpleLedger)
        ->registerProjector('cash-account', new DatabaseRequiredProjector(throws: true));
    $abacus->overrideConnection('projection_test');

    expect(fn () => $abacus->post('cash-account', 'one', projectionAmount(10), projectionContext()))
        ->toThrow(RuntimeException::class, 'required projection failed')
        ->and(DB::connection('projection_test')->table('ledger_operation')->count())->toBe(0)
        ->and(DB::connection('projection_test')->table('ledger_transaction')->count())->toBe(0)
        ->and(DB::connection('projection_test')->table('ledger_stream_head')->count())->toBe(0)
        ->and(DB::connection('projection_test')->table('required_projection')->count())->toBe(0);
});

final class RecordingEntryProjector implements Projector
{
    /** @var list<array{int, int, string}> */
    public static array $calls = [];

    public function project(
        LedgerTransaction $transaction,
        LedgerPayload $payload,
        PostingContext $context,
        Connection $connection,
    ): void {
        self::$calls[] = [
            $transaction->operation_position,
            (int) $connection->table('ledger_stream_head')->where([
                'ledger_type' => $transaction->ledger_type,
                'ledger_id' => $transaction->ledger_id,
            ])->value('version'),
            $connection->getName(),
        ];
    }
}

final class RecordingOperationProjector implements OperationProjector
{
    /** @var list<array{string, list<int>, list<string>}> */
    public static array $calls = [];

    public function projectOperation(
        LedgerOperation $operation,
        Collection $transactions,
        PostingContext $context,
        Connection $connection,
    ): void {
        self::$calls[] = [
            $operation->id,
            $transactions->pluck('operation_position')->all(),
            $transactions->pluck('ledger_type')->all(),
        ];
    }
}

final class DatabaseRequiredProjector implements Projector
{
    public function __construct(private bool $throws = false) {}

    public function project(
        LedgerTransaction $transaction,
        LedgerPayload $payload,
        PostingContext $context,
        Connection $connection,
    ): void {
        $connection->table('required_projection')->insert(['transaction_id' => $transaction->id]);

        if ($this->throws) {
            throw new RuntimeException('required projection failed');
        }
    }
}

final class ProjectingLedger implements HasProjectors, Ledger
{
    public function getLedgerType(): string
    {
        return 'projecting-account';
    }

    /** @return array{total: int} */
    public function initializeAggregate(): array
    {
        return ['total' => 0];
    }

    /** @param array{total: int}|JsonSerializable $existingAggregate */
    public function applyToAggregate(LedgerPayload $payload, array|JsonSerializable $existingAggregate): array
    {
        if (! $payload instanceof GenericPayload || ! is_array($existingAggregate)) {
            throw new InvalidArgumentException('Unsupported payload.');
        }

        return ['total' => $existingAggregate['total'] + $payload->payload()['amount']];
    }

    public function assertValidPayload(LedgerPayload $payload): void
    {
        if (! $payload instanceof GenericPayload) {
            throw new InvalidArgumentException('Unsupported payload.');
        }
    }

    public function assertAggregateInvariants(array|JsonSerializable $aggregate): void {}

    public function computeOpposing(LedgerPayload $payload): LedgerPayload
    {
        if (! $payload instanceof GenericPayload) {
            throw new InvalidArgumentException('Unsupported payload.');
        }

        return GenericPayload::make($payload->payloadType(), [
            'amount' => -$payload->payload()['amount'],
        ]);
    }

    public function projectors(): array
    {
        return [RecordingEntryProjector::class];
    }
}

function projectionAmount(int $amount): GenericPayload
{
    return GenericPayload::make('entry', ['amount' => $amount]);
}

function projectionContext(): PostingContext
{
    return PostingContext::forProcess(
        'projection-test',
        now(),
        now(),
        'test required projections',
    );
}
