<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Latencia de evaluación (en microsegundos): tiempo transcurrido desde que el
 * order book disparador llega al engine hasta que se toma la decisión sobre la
 * oportunidad. Permite medir el costo de procesamiento del pipeline y vigilar
 * que la evaluación siga dentro de presupuesto de latencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->unsignedInteger('evaluation_latency_us')->nullable()->after('detected_at_ms');
        });

        Schema::table('triangular_opportunities', function (Blueprint $table) {
            $table->unsignedInteger('evaluation_latency_us')->nullable()->after('executed_at_ms');
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropColumn('evaluation_latency_us');
        });

        Schema::table('triangular_opportunities', function (Blueprint $table) {
            $table->dropColumn('evaluation_latency_us');
        });
    }
};
