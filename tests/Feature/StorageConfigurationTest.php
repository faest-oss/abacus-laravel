<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Faest\Abacus\Abacus as AbacusCoordinator;
use Faest\Abacus\Data\GenericPayload;
use Faest\Abacus\Data\PostingContext;
use Faest\Abacus\Data\TransactionCriteria;
use Faest\Abacus\Enums\ReversalStatus;
use Faest\Abacus\Models\LedgerOperation;
use Faest\Abacus\Models\LedgerSnapshot;
use Faest\Abacus\Models\LedgerTransaction;
use Faest\Abacus\PayloadRegistry;
use Faest\Abacus\Support\StorageConfiguration;
use Faest\Abacus\Tests\Fixtures\SimpleLedger;
use Faest\Abacus\Tests\Fixtures\SnapshotLedger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('uses a configured connection, schema, and table names throughout the package', function () {
    configureStorageConnection('storage_configured');
    configureStorageTables();
    config()->set('abacus.connection', 'storage_configured');
    config()->set('abacus.schema', 'main');

    $migration = abacusStorageMigration();
    $migration->up();

    try {
        $abacus = app(AbacusCoordinator::class)->registerLedger(new SnapshotLedger);
        $posted = $abacus->post(
            'snapshot-account',
            'configured-stream',
            storageAmount(25),
            storageContext(),
        );
        $abacus->reverse($posted->id, storageContext());

        expect($abacus->createAggregateSnapshot('snapshot-account', 'configured-stream'))->toBe(2)
            ->and($abacus->streamVersion('snapshot-account', 'configured-stream'))->toBe(2)
            ->and($abacus->getAggregate('snapshot-account', 'configured-stream'))->toBe(['total' => 0])
            ->and($abacus->operationsForStream('snapshot-account', 'configured-stream')->count())->toBe(2)
            ->and($abacus->transactionsForStream(
                'snapshot-account',
                'configured-stream',
                new TransactionCriteria(reversalStatus: ReversalStatus::Reversed),
            )->count())->toBe(1)
            ->and(LedgerTransaction::query()->count())->toBe(2)
            ->and(LedgerOperation::query()->with('transactions')->get()->sum(
                fn (LedgerOperation $operation): int => $operation->transactions->count(),
            ))->toBe(2)
            ->and(LedgerSnapshot::query()->count())->toBe(1)
            ->and(DB::connection('storage_configured')->table('main.custom_stream_heads')->count())->toBe(1)
            ->and(DB::connection('storage_configured')->table('main.custom_operations')->count())->toBe(2)
            ->and(DB::connection('storage_configured')->table('main.custom_transactions')->count())->toBe(2)
            ->and(DB::connection('storage_configured')->table('main.custom_snapshots')->count())->toBe(1);
    } finally {
        $migration->down();
    }

    expect(Schema::connection('storage_configured')->hasTable('main.custom_stream_heads'))->toBeFalse()
        ->and(Schema::connection('storage_configured')->hasTable('main.custom_operations'))->toBeFalse()
        ->and(Schema::connection('storage_configured')->hasTable('main.custom_transactions'))->toBeFalse()
        ->and(Schema::connection('storage_configured')->hasTable('main.custom_snapshots'))->toBeFalse();

    resetStorageConfiguration();
});

it('gives an explicit runtime connection override precedence over configuration', function () {
    configureStorageConnection('storage_configured');
    configureStorageConnection('storage_override');
    configureStorageTables();
    config()->set('abacus.schema', 'main');

    config()->set('abacus.connection', 'storage_configured');
    $configuredMigration = abacusStorageMigration();
    $configuredMigration->up();

    config()->set('abacus.connection', 'storage_override');
    $overrideMigration = abacusStorageMigration();
    $overrideMigration->up();

    config()->set('abacus.connection', 'storage_configured');

    try {
        $abacus = (new AbacusCoordinator(new PayloadRegistry, app(StorageConfiguration::class)))
            ->registerLedger(new SimpleLedger);
        $abacus->overrideConnection('storage_override');
        $abacus->post('cash-account', 'override-stream', storageAmount(10), storageContext());

        expect(DB::connection('storage_configured')->table('main.custom_transactions')->count())->toBe(0)
            ->and(DB::connection('storage_override')->table('main.custom_transactions')->count())->toBe(1);
    } finally {
        config()->set('abacus.connection', 'storage_override');
        $overrideMigration->down();
        config()->set('abacus.connection', 'storage_configured');
        $configuredMigration->down();
        resetStorageConfiguration();
    }
});

it('supports a non-default PostgreSQL schema', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL is required for non-default schema coverage.');
    }

    $schemaName = 'abacus_storage_test';
    DB::statement('DROP SCHEMA IF EXISTS abacus_storage_test CASCADE');
    DB::statement('CREATE SCHEMA abacus_storage_test');
    configureStorageTables();
    config()->set('abacus.schema', $schemaName);

    $migration = abacusStorageMigration();
    $migration->up();

    try {
        $abacus = app(AbacusCoordinator::class)->registerLedger(new SnapshotLedger);
        $posted = $abacus->post(
            'snapshot-account',
            'postgres-stream',
            storageAmount(15),
            storageContext(),
        );

        expect($abacus->findTransaction($posted->id))->not->toBeNull()
            ->and($abacus->createAggregateSnapshot('snapshot-account', 'postgres-stream'))->toBe(1)
            ->and(DB::table("{$schemaName}.custom_transactions")->count())->toBe(1)
            ->and(DB::table("{$schemaName}.custom_snapshots")->count())->toBe(1);
    } finally {
        $migration->down();
        DB::statement('DROP SCHEMA IF EXISTS abacus_storage_test CASCADE');
        resetStorageConfiguration();
    }
});

it('rejects empty and qualified storage identifiers', function (
    string $key,
    mixed $value,
    string $method,
    array $arguments = [],
) {
    $original = config($key);
    config()->set($key, $value);

    try {
        expect(fn () => app(StorageConfiguration::class)->{$method}(...$arguments))
            ->toThrow(InvalidArgumentException::class, 'non-empty, unqualified database identifier');
    } finally {
        config()->set($key, $original);
    }
})->with([
    'empty schema' => ['abacus.schema', '', 'schema'],
    'qualified schema' => ['abacus.schema', 'accounting.archive', 'schema'],
    'empty table' => ['abacus.tables.ledger_transaction', '', 'table', ['ledger_transaction']],
    'qualified table' => [
        'abacus.tables.ledger_transaction',
        'accounting.transactions',
        'table',
        ['ledger_transaction'],
    ],
]);

it('rejects an empty configured connection', function () {
    $original = config('abacus.connection');
    config()->set('abacus.connection', '');

    try {
        expect(fn () => app(StorageConfiguration::class)->connection())
            ->toThrow(InvalidArgumentException::class, 'non-empty string');
    } finally {
        config()->set('abacus.connection', $original);
    }
});

function configureStorageConnection(string $name): void
{
    config()->set("database.connections.{$name}", [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
}

function configureStorageTables(): void
{
    config()->set('abacus.tables', [
        'ledger_stream_head' => 'custom_stream_heads',
        'ledger_operation' => 'custom_operations',
        'ledger_transaction' => 'custom_transactions',
        'ledger_snapshot' => 'custom_snapshots',
    ]);
}

function resetStorageConfiguration(): void
{
    config()->set('abacus.connection');
    config()->set('abacus.schema');
    config()->set('abacus.tables', [
        'ledger_stream_head' => 'ledger_stream_head',
        'ledger_operation' => 'ledger_operation',
        'ledger_transaction' => 'ledger_transaction',
        'ledger_snapshot' => 'ledger_snapshot',
    ]);
}

function abacusStorageMigration(): Migration
{
    return require __DIR__.'/../../database/migrations/2026_01_01_000000_create_abacus_tables.php';
}

function storageAmount(int $amount): GenericPayload
{
    return GenericPayload::make('entry', ['amount' => $amount]);
}

function storageContext(): PostingContext
{
    return PostingContext::forProcess(
        'storage-test',
        CarbonImmutable::parse('2026-09-01'),
        CarbonImmutable::parse('2026-09-01'),
        'storage configuration test',
    );
}
