<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persiste el desglose de costos de cada oportunidad (fee de compra/venta,
 * slippage por profundidad, penalización por latencia y costo fijo) para que el
 * dashboard pueda mostrar y validar cada componente, no solo el total agregado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->decimal('buy_fee', 36, 18)->default(0)->after('total_costs');
            $table->decimal('sell_fee', 36, 18)->default(0)->after('buy_fee');
            $table->decimal('slippage_cost', 36, 18)->default(0)->after('sell_fee');
            $table->decimal('latency_penalty', 36, 18)->default(0)->after('slippage_cost');
            $table->decimal('fixed_cost', 36, 18)->default(0)->after('latency_penalty');
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropColumn([
                'buy_fee',
                'sell_fee',
                'slippage_cost',
                'latency_penalty',
                'fixed_cost',
            ]);
        });
    }
};
