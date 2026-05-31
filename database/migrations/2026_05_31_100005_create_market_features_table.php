<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_features', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->string('exchange', 32);
            $table->decimal('mid_price', 36, 18)->nullable();
            $table->decimal('return_1m', 12, 6)->nullable();
            $table->decimal('return_5m', 12, 6)->nullable();
            $table->decimal('volume_spike', 12, 6)->nullable();
            $table->decimal('z_score', 16, 6)->nullable();
            $table->decimal('spread_pct', 12, 6)->nullable();
            $table->decimal('bid_depth', 36, 18)->nullable();
            $table->decimal('ask_depth', 36, 18)->nullable();
            $table->decimal('imbalance', 12, 6)->nullable();
            $table->decimal('volatility', 12, 6)->nullable();
            $table->timestamps();

            $table->index(['symbol', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_features');
    }
};
