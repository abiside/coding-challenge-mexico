<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arbitrage_settings', function (Blueprint $table) {
            // ¿El autopilot promueve automáticamente al nuevo champion? Si está
            // apagado, el juez sigue evaluando y recomendando, pero la promoción
            // queda en manos del usuario (botón manual).
            $table->boolean('autopilot_auto_promote')->default(true)->after('autopilot_max_challengers');
            // Periodo mínimo (minutos) entre promociones automáticas: define cada
            // cuánto puede entrar en operación un nuevo champion.
            $table->unsignedSmallInteger('autopilot_interval_minutes')->default(10)->after('autopilot_auto_promote');
        });
    }

    public function down(): void
    {
        Schema::table('arbitrage_settings', function (Blueprint $table) {
            $table->dropColumn(['autopilot_auto_promote', 'autopilot_interval_minutes']);
        });
    }
};
