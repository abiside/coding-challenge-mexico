<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('triangular_opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->index();
            $table->foreignId('strategy_id')->nullable()->index();

            $table->string('label');
            $table->string('start_asset');
            $table->string('start_exchange');
            $table->unsignedTinyInteger('cycle_length');

            $table->decimal('gross_spread_bps', 18, 6)->default(0);
            $table->decimal('net_rate_product', 24, 16)->default(0);

            $table->decimal('start_amount', 36, 18)->default(0);
            $table->decimal('end_amount', 36, 18)->default(0);
            $table->decimal('gross_profit', 36, 18)->default(0);
            $table->decimal('net_profit', 36, 18)->default(0);
            $table->decimal('net_margin', 18, 10)->default(0);
            $table->decimal('total_costs', 36, 18)->default(0);
            $table->decimal('total_fees', 36, 18)->default(0);
            $table->decimal('latency_penalty', 36, 18)->default(0);
            $table->decimal('fixed_cost', 36, 18)->default(0);

            $table->decimal('realized_pnl', 36, 18)->nullable();
            $table->decimal('execution_delta', 36, 18)->nullable();

            $table->boolean('partial_fill')->default(false);
            $table->string('decision', 16);
            $table->json('reasons')->nullable();
            $table->json('legs')->nullable();
            $table->json('exchanges')->nullable();
            $table->string('idempotency_key', 191)->nullable()->index();
            $table->unsignedBigInteger('detected_at_ms');
            $table->unsignedBigInteger('executed_at_ms')->nullable();
            $table->timestamps();

            $table->index(['start_asset', 'decision']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('triangular_opportunities');
    }
};
