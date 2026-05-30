<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arbitrage_settings', function (Blueprint $table) {
            $table->boolean('autopilot_enabled')->default(false)->after('circuit_breaker_enabled');
            // Objetivo de optimización: net_pnl (default) | volume | risk_adjusted.
            $table->string('optimization_objective', 24)->default('net_pnl')->after('autopilot_enabled');
            // Máximo de challengers shadow corriendo en paralelo por usuario.
            $table->unsignedTinyInteger('autopilot_max_challengers')->default(2)->after('optimization_objective');
        });
    }

    public function down(): void
    {
        Schema::table('arbitrage_settings', function (Blueprint $table) {
            $table->dropColumn(['autopilot_enabled', 'optimization_objective', 'autopilot_max_challengers']);
        });
    }
};
