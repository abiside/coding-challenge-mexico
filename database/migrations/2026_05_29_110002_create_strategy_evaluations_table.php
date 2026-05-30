<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_id')->constrained('arbitrage_strategies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Ventana de observación.
            $table->unsignedBigInteger('window_start_ms');
            $table->unsignedBigInteger('window_end_ms');
            $table->unsignedInteger('snapshots')->default(0);
            $table->unsignedInteger('candidates')->default(0);
            $table->unsignedInteger('executions')->default(0);
            $table->unsignedInteger('rejects')->default(0);
            $table->unsignedInteger('ignores')->default(0);
            $table->double('realized_pnl')->default(0);
            $table->double('executed_volume')->default(0);
            $table->double('avg_margin')->default(0);
            // Score normalizado de la ventana según el objetivo (default: pnl).
            $table->double('score')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['strategy_id', 'window_end_ms']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_evaluations');
    }
};
