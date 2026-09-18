<?php

declare(strict_types=1);

use Faest\Abacus\Support\StorageConfiguration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $storage = app(StorageConfiguration::class);
        $connection = DB::connection($storage->connection());
        $schema = $connection->getSchemaBuilder();
        $streamHeadTable = $storage->table('ledger_stream_head');
        $operationTable = $storage->table('ledger_operation');
        $transactionTable = $storage->table('ledger_transaction');
        $snapshotTable = $storage->table('ledger_snapshot');
        $streamHeadReference = $storage->table(
            'ledger_stream_head',
            qualified: $connection->getDriverName() !== 'sqlite',
        );
        $operationReference = $storage->table(
            'ledger_operation',
            qualified: $connection->getDriverName() !== 'sqlite',
        );
        $transactionReference = $storage->table(
            'ledger_transaction',
            qualified: $connection->getDriverName() !== 'sqlite',
        );

        $schema->create($streamHeadTable, function (Blueprint $table) {
            $table->string('ledger_type');
            $table->string('ledger_id');
            $table->bigInteger('version')->default(0);
            $table->primary(['ledger_type', 'ledger_id']);
        });

        $schema->create($operationTable, function (Blueprint $table) use ($operationReference) {
            $table->ulid('id');
            $table->primary('id');
            $table->string('kind');
            $table->string('actor');
            $table->string('reason');
            $table->timestamp('event_date');
            $table->timestamp('accounting_date');
            $table->timestamp('system_date');
            $table->uuid('correlation_id')->nullable();
            $table->json('metadata');
            $table->string('idempotency_key')->nullable()->unique('abacus_operation_idempotency_unique');
            $table->unsignedSmallInteger('request_fingerprint_version');
            $table->string('request_fingerprint', 64);
            $table->ulid('reverses_operation_id')->nullable();

            $table->foreign('reverses_operation_id', 'abacus_operation_reversal_foreign')
                ->references('id')
                ->on($operationReference)
                ->restrictOnDelete();
        });

        $schema->create($transactionTable, function (Blueprint $table) use (
            $operationReference,
            $streamHeadReference,
            $transactionReference,
        ) {
            $table->ulid('id');
            $table->primary('id');
            $table->ulid('operation_id');
            $table->unsignedInteger('operation_position');
            $table->string('ledger_type');
            $table->string('ledger_id');
            $table->bigInteger('stream_version');
            $table->string('payload_type');
            $table->json('payload');
            $table->string('reason');
            $table->ulid('reverses_transaction_id')->nullable();
            $table->ulid('adjusts_transaction_id')->nullable();
            $table->ulid('replaces_transaction_id')->nullable();
            $table->uuid('correlation_id')->nullable(true);
            $table->timestamp('event_date');
            $table->timestamp('system_date');
            $table->timestamp('accounting_date');
            $table->string('actor');

            $table->unique(['ledger_type', 'ledger_id', 'stream_version']);
            $table->unique(['operation_id', 'operation_position']);
            $table->unique('reverses_transaction_id', 'abacus_transaction_reversal_unique');
            $table->unique('replaces_transaction_id', 'abacus_transaction_replacement_unique');
            $table->foreign('operation_id', 'abacus_transaction_operation_foreign')
                ->references('id')
                ->on($operationReference)
                ->restrictOnDelete();
            $table->foreign('reverses_transaction_id', 'abacus_transaction_reversal_foreign')
                ->references('id')
                ->on($transactionReference)
                ->restrictOnDelete();
            $table->foreign('adjusts_transaction_id', 'abacus_transaction_adjustment_foreign')
                ->references('id')
                ->on($transactionReference)
                ->restrictOnDelete();
            $table->foreign('replaces_transaction_id', 'abacus_transaction_replacement_foreign')
                ->references('id')
                ->on($transactionReference)
                ->restrictOnDelete();
            $table->foreign(
                ['ledger_type', 'ledger_id'],
                'abacus_transaction_stream_foreign',
            )
                ->references(['ledger_type', 'ledger_id'])
                ->on($streamHeadReference)
                ->restrictOnDelete();

            $table->index(
                ['ledger_type', 'ledger_id', 'event_date', 'stream_version'],
                'ledger_transaction_event_stream_index',
            );
            $table->index(
                ['ledger_type', 'ledger_id', 'accounting_date', 'stream_version'],
                'ledger_transaction_accounting_stream_index',
            );
            $table->index(
                ['ledger_type', 'ledger_id', 'system_date', 'stream_version'],
                'ledger_transaction_system_stream_index',
            );
            $table->index(
                ['ledger_type', 'ledger_id', 'correlation_id', 'stream_version'],
                'ledger_transaction_correlation_stream_index',
            );
            $table->index('adjusts_transaction_id');
        });

        $schema->create($snapshotTable, function (Blueprint $table) use (
            $operationReference,
            $streamHeadReference,
        ) {
            $table->ulid('id');
            $table->primary('id');
            $table->string('ledger_type');
            $table->string('ledger_id');
            $table->bigInteger('stream_version');
            $table->ulid('operation_id');
            $table->unsignedInteger('snapshot_version');
            $table->json('aggregate');
            $table->timestamp('max_event_date');
            $table->timestamp('max_accounting_date');
            $table->timestamp('max_system_date');
            $table->timestamp('created_at');

            $table->unique(
                ['ledger_type', 'ledger_id', 'stream_version', 'snapshot_version'],
                'ledger_snapshot_stream_format_unique',
            );
            $table->index(
                ['ledger_type', 'ledger_id', 'snapshot_version', 'stream_version'],
                'ledger_snapshot_lookup_index',
            );
            $table->foreign(
                ['ledger_type', 'ledger_id'],
                'abacus_snapshot_stream_foreign',
            )
                ->references(['ledger_type', 'ledger_id'])
                ->on($streamHeadReference)
                ->restrictOnDelete();
            $table->foreign('operation_id', 'abacus_snapshot_operation_foreign')
                ->references('id')
                ->on($operationReference)
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $storage = app(StorageConfiguration::class);
        $schema = DB::connection($storage->connection())->getSchemaBuilder();

        $schema->withoutForeignKeyConstraints(function () use ($schema, $storage): void {
            $schema->dropIfExists($storage->table('ledger_snapshot'));
            $schema->dropIfExists($storage->table('ledger_transaction'));
            $schema->dropIfExists($storage->table('ledger_operation'));
            $schema->dropIfExists($storage->table('ledger_stream_head'));
        });
    }
};
