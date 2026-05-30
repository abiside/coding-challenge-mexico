<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arbitrage_strategies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // champion = settings aplicados | challenger = shadow paralelo | archived = retirado.
            $table->string('status', 16)->default('challenger');
            // baseline = clon del champion al activar autopilot | manual | agent.
            $table->string('origin', 16)->default('agent');
            // Linaje: estrategia padre de la que se perturbó este config.
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedInteger('generation')->default(0);
            // Config completa que consume EngineFactory (toEngineConfig).
            $table->json('config');
            // Hash determinista del config para detectar cambios sin diff profundo.
            $table->string('config_hash', 64);
            // Score cacheado (P&L acumulado ponderado por ventanas recientes).
            $table->double('score')->default(0);
            // Explicación legible del LLM (o resumen del optimizador).
            $table->text('rationale')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'config_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arbitrage_strategies');
    }
};
