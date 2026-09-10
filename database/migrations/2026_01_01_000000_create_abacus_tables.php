<?php

declare(strict_types=1);

use Faest\Abacus\Models\LedgerTransaction;
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

        Schema::create('ledger_transaction', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('ledger_type');
            $table->string('ledger_id');
            $table->bigInteger('stream_version');
            $table->string('payload_type');
            $table->json('payload');
            $table->string('reason');
            $table->foreignUlidFor(LedgerTransaction::class, 'reverses_transaction_id')->nullable(true);
            $table->foreignUlidFor(LedgerTransaction::class, 'adjusts_transaction_id')->nullable(true);
            $table->uuid('correlation_id')->nullable(true);
            $table->string('idempotency_key')->nullable(true);
            $table->timestamp('event_date');
            $table->timestamp('system_date');
            $table->timestamp('accounting_date');
            $table->string('actor');

            $table->unique(['ledger_type', 'ledger_id', 'stream_version']);
            $table->unique(['ledger_type', 'idempotency_key']);
            $table->foreign(['ledger_type', 'ledger_id'])
                ->references(['ledger_type', 'ledger_id'])
                ->on('ledger_stream_head')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_transaction');
        Schema::dropIfExists('ledger_stream_head');
    }
};
