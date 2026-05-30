<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mean_reversion_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // active | stopped
            $table->string('status', 16)->default('active');
            $table->decimal('initial_usdt', 36, 18)->default(10000);
            // Parámetros de la estrategia para esta sesión (slice, z-score, etc.).
            $table->json('params')->nullable();
            // Snapshots para continuidad tras reinicios del worker.
            $table->json('wallet_snapshot')->nullable();
            $table->json('position_snapshot')->nullable();
            $table->decimal('realized_pnl', 36, 18)->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamps();

            // Una sola sesión por usuario (se reusa/reactiva).
            $table->unique('user_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mean_reversion_sessions');
    }
};
