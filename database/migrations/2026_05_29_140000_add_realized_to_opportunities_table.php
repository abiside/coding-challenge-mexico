<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Captura, en la propia oportunidad, el resultado realmente ejecutado y su
 * divergencia respecto a lo evaluado. Permite ver "lo identificado vs. lo que
 * realmente fue" sin tener que cruzar con la tabla de trades; es la señal que
 * el modo simulación con slippage de ejecución hace visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            // P&L realizado tras la ejecución (null si no se ejecutó).
            $table->decimal('realized_pnl', 36, 18)->nullable()->after('net_profit');
            // realized_pnl − net_profit evaluado: positivo = mejor de lo esperado.
            $table->decimal('execution_delta', 36, 18)->nullable()->after('realized_pnl');
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropColumn(['realized_pnl', 'execution_delta']);
        });
    }
};
