<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mean_reversion_trades', function (Blueprint $table) {
            $table->id();
            $table->string('exchange', 32);
            $table->string('symbol');
            // buy | sell
            $table->string('side', 8);
            // zscore_entry | zscore_exit | take_profit | stop_loss | ...
            $table->string('reason', 32);
            $table->decimal('price', 36, 18);
            $table->decimal('base_quantity', 36, 18);
            $table->decimal('quote_amount', 36, 18);
            $table->decimal('fee', 36, 18)->default(0);
            $table->decimal('realized_pnl', 36, 18)->default(0);
            $table->decimal('z_score', 16, 6)->nullable();
            // Idempotencia: evita duplicar la misma ejecución simulada.
            $table->string('idempotency_key')->unique();
            $table->unsignedBigInteger('executed_at_ms');
            $table->timestamps();

            $table->index(['symbol', 'created_at']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mean_reversion_trades');
    }
};
