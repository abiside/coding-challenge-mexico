<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->nullable()->constrained('opportunities')->nullOnDelete();
            $table->string('symbol');
            $table->string('buy_exchange');
            $table->string('sell_exchange');
            $table->decimal('base_volume', 36, 18);
            $table->decimal('realized_pnl', 36, 18)->default(0);
            $table->string('status', 16)->default('simulated');
            // Idempotencia: evita duplicar la misma ejecución simulada.
            $table->string('idempotency_key')->unique();
            $table->unsignedBigInteger('executed_at_ms');
            $table->timestamps();

            $table->index(['symbol', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
