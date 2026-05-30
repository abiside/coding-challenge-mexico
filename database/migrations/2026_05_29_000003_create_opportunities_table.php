<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->string('buy_exchange');
            $table->string('sell_exchange');
            $table->decimal('buy_ask', 36, 18);
            $table->decimal('sell_bid', 36, 18);
            $table->decimal('gross_spread_bps', 18, 6)->default(0);
            $table->decimal('base_volume', 36, 18)->default(0);
            $table->decimal('weighted_buy_price', 36, 18)->default(0);
            $table->decimal('weighted_sell_price', 36, 18)->default(0);
            $table->decimal('gross_profit', 36, 18)->default(0);
            $table->decimal('net_profit', 36, 18)->default(0);
            $table->decimal('net_margin', 18, 10)->default(0);
            $table->decimal('total_costs', 36, 18)->default(0);
            $table->boolean('partial_fill')->default(false);
            $table->string('decision', 16);
            $table->json('reasons')->nullable();
            $table->unsignedBigInteger('detected_at_ms');
            $table->timestamps();

            $table->index(['symbol', 'decision']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunities');
    }
};
