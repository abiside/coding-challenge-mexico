<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arbitrage_settings', function (Blueprint $table) {
            // Modo simulación: inyecta jitter sintético sobre los precios del
            // order book para forzar spreads cross-exchange rentables (pruebas).
            $table->boolean('simulation_enabled')->default(false)->after('autopilot_max_challengers');
            // Deriva máxima (en %) que se aplica al precio real de cada book.
            $table->decimal('simulation_max_drift_pct', 8, 4)->default(0)->after('simulation_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('arbitrage_settings', function (Blueprint $table) {
            $table->dropColumn(['simulation_enabled', 'simulation_max_drift_pct']);
        });
    }
};
