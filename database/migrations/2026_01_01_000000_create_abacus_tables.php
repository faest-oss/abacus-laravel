<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_stream_head', function (Blueprint $table) {
            $table->string('ledger_type');
            $table->string('ledger_id');
            $table->bigInteger('version')->default(0);
            $table->primary(['ledger_type', 'ledger_id']);
        });

        Schema::create('ledger_operation', function (Blueprint $table) {
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
            $table->string('idempotency_key')->nullable()->unique();
            $table->unsignedSmallInteger('request_fingerprint_version');
            $table->string('request_fingerprint', 64);
            $table->ulid('reverses_operation_id')->nullable();

            $table->foreign('reverses_operation_id')
                ->references('id')
                ->on('ledger_operation')
                ->restrictOnDelete();
        });

        Schema::create('ledger_transaction', function (Blueprint $table) {
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
            $table->unique('reverses_transaction_id');
            $table->unique('replaces_transaction_id');
            $table->foreign('operation_id')
                ->references('id')
                ->on('ledger_operation')
                ->restrictOnDelete();
            $table->foreign('reverses_transaction_id')
                ->references('id')
                ->on('ledger_transaction')
                ->restrictOnDelete();
            $table->foreign('adjusts_transaction_id')
                ->references('id')
                ->on('ledger_transaction')
                ->restrictOnDelete();
            $table->foreign('replaces_transaction_id')
                ->references('id')
                ->on('ledger_transaction')
                ->restrictOnDelete();
            $table->foreign(['ledger_type', 'ledger_id'])
                ->references(['ledger_type', 'ledger_id'])
                ->on('ledger_stream_head')
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

        Schema::create('ledger_snapshot', function (Blueprint $table) {
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
            $table->foreign(['ledger_type', 'ledger_id'])
                ->references(['ledger_type', 'ledger_id'])
                ->on('ledger_stream_head')
                ->restrictOnDelete();
            $table->foreign('operation_id')
                ->references('id')
                ->on('ledger_operation')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_snapshot');
        Schema::dropIfExists('ledger_transaction');
        Schema::dropIfExists('ledger_operation');
        Schema::dropIfExists('ledger_stream_head');
    }
};
