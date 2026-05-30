<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arbitrage_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('symbols')->nullable();
            $table->decimal('min_net_profit', 36, 18)->default(1);
            $table->decimal('min_net_margin', 18, 10)->default(0.0005);
            $table->decimal('min_base_volume', 36, 18)->default(0.0001);
            $table->decimal('max_base_volume', 36, 18)->default(1);
            $table->unsignedInteger('freshness_ms')->default(2000);
            $table->unsignedInteger('latency_max_ms')->default(1500);
            // Override de fees por exchange; si null usa los defaults de config.
            $table->json('fees')->nullable();
            $table->boolean('circuit_breaker_enabled')->default(true);
            $table->boolean('onboarded')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arbitrage_settings');
    }
};
