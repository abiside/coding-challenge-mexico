<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // cross_exchange | trading
            $table->string('type', 24)->default('trading');
            // Para trading: mean_reversion_long | mean_reversion_short | pump_exhaustion_short | ...
            // Para cross_exchange: null (envuelve el arbitraje existente).
            $table->string('algorithm', 48)->nullable();
            // active | stopped
            $table->string('status', 16)->default('stopped');
            $table->boolean('enabled')->default(true);
            $table->decimal('initial_usdt', 36, 18)->default(10000);
            // Parámetros de la estrategia (TP/SL, size, umbrales, etc.).
            $table->json('config')->nullable();
            // Snapshots para continuidad tras reinicios del worker.
            $table->json('wallet_snapshot')->nullable();
            $table->json('position_snapshot')->nullable();
            $table->decimal('realized_pnl', 36, 18)->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategies');
    }
};
