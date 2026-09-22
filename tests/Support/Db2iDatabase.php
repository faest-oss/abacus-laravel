<?php

declare(strict_types=1);

namespace Faest\Abacus\Tests\Support;

use Faest\Abacus\Support\StorageConfiguration;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Db2iDatabase
{
    public static function reset(): void
    {
        $connectionName = (string) config('database.default');
        $connection = DB::connection($connectionName);
        $schema = (string) $connection->getConfig('schema');

        if ($connection->getDriverName() !== 'db2' || $schema === '') {
            throw new RuntimeException('DB2 for i tests require an explicitly configured schema.');
        }

        if (filter_var(env('DB2I_ALLOW_DESTRUCTIVE_TESTS'), FILTER_VALIDATE_BOOL) !== true) {
            throw new RuntimeException('DB2 for i test table cleanup has not been acknowledged.');
        }

        $storage = app(StorageConfiguration::class);
        $builder = $connection->getSchemaBuilder();

        self::dropForeignKeys($connection, $schema, $storage);

        foreach (self::tables($storage) as $table) {
            $builder->dropIfExists("{$schema}.{$table}");
        }

        self::migration()->up();
    }

    private static function dropForeignKeys(
        Connection $connection,
        string $schema,
        StorageConfiguration $storage,
    ): void {
        $foreignKeys = self::foreignKeys($storage);
        $builder = $connection->getSchemaBuilder();
        $constraints = $connection->select(
            <<<'SQL'
                select table_name, constraint_name
                from qsys2.syscst
                where table_schema = ?
                  and constraint_type = 'FOREIGN KEY'
                SQL,
            [strtoupper($schema)],
        );

        foreach ($constraints as $constraint) {
            $table = strtoupper(trim((string) $constraint->table_name));
            $name = strtoupper(trim((string) $constraint->constraint_name));

            if (! in_array($name, $foreignKeys[$table] ?? [], true)) {
                continue;
            }

            $builder->table(
                "{$schema}.{$table}",
                static fn (Blueprint $blueprint) => $blueprint->dropForeign($name),
            );
        }
    }

    /**
     * Only constraints created by the Abacus migration are eligible for cleanup.
     *
     * @return array<string, list<string>>
     */
    private static function foreignKeys(StorageConfiguration $storage): array
    {
        return [
            strtoupper($storage->table('ledger_snapshot', qualified: false)) => [
                'ABACUS_SNAPSHOT_STREAM_FOREIGN',
                'ABACUS_SNAPSHOT_OPERATION_FOREIGN',
            ],
            strtoupper($storage->table('ledger_transaction', qualified: false)) => [
                'ABACUS_TRANSACTION_OPERATION_FOREIGN',
                'ABACUS_TRANSACTION_REVERSAL_FOREIGN',
                'ABACUS_TRANSACTION_ADJUSTMENT_FOREIGN',
                'ABACUS_TRANSACTION_REPLACEMENT_FOREIGN',
                'ABACUS_TRANSACTION_STREAM_FOREIGN',
            ],
            strtoupper($storage->table('ledger_operation', qualified: false)) => [
                'ABACUS_OPERATION_REVERSAL_FOREIGN',
            ],
        ];
    }

    /**
     * Drop dependants before the tables they reference.
     *
     * @return list<string>
     */
    private static function tables(StorageConfiguration $storage): array
    {
        return [
            'replay_projection',
            'required_projection',
            $storage->table('ledger_snapshot', qualified: false),
            $storage->table('ledger_transaction', qualified: false),
            $storage->table('ledger_operation', qualified: false),
            $storage->table('ledger_stream_head', qualified: false),
        ];
    }

    private static function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_01_01_000000_create_abacus_tables.php';

        return $migration;
    }
}
