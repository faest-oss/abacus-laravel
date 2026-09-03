<?php

declare(strict_types=1);

use Faest\Abacus\Models\LedgerTransaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ledger_transaction', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('ledger_type');
            $table->string('ledger_id');
            $table->unsignedBigInteger('stream_version');
            $table->string('payload_type');
            $table->json('payload');
            $table->string('reason');
            $table->foreignUlidFor(LedgerTransaction::class, 'reverses_transaction_id')->nullable(true);
            $table->foreignUlidFor(LedgerTransaction::class, 'adjusts_transaction_id')->nullable(true);
            $table->uuid('correlation_id')->nullable(true);
            $table->timestamp('effective_at');
            $table->timestamp('recorded_at');
            $table->timestamp('accounting_date');
            $table->string('entered_by_user_id');

            $table->unique(['ledger_type', 'ledger_id', 'stream_version']);
        });

        Schema::create('ledger_stream_head', function (Blueprint $table) {
            $table->string('ledger_type');
            $table->string('ledger_id');
            $table->unsignedBigInteger('version')->default(0);
            $table->primary(['ledger_type', 'ledger_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_transaction');
        Schema::dropIfExists('ledger_stream_head');
    }
};
