<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arbitrage_settings', function (Blueprint $table) {
            // Slippage de ejecución simulado: deriva máxima (%) que se aplica al
            // precio de fill (compra/venta) respecto al precio evaluado, al
            // momento del trade. Solo aplica en modo simulación.
            $table->decimal('simulation_max_exec_drift_pct', 8, 4)->default(0)->after('simulation_max_drift_pct');
        });
    }

    public function down(): void
    {
        Schema::table('arbitrage_settings', function (Blueprint $table) {
            $table->dropColumn('simulation_max_exec_drift_pct');
        });
    }
};
