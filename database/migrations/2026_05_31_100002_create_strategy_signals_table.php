<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('algorithm', 48);
            $table->string('symbol');
            // long | short
            $table->string('side', 8);
            $table->decimal('confidence_score', 8, 4)->default(0);
            $table->decimal('entry_price', 36, 18);
            $table->decimal('suggested_size', 36, 18)->default(0);
            $table->decimal('take_profit', 36, 18)->nullable();
            $table->decimal('stop_loss', 36, 18)->nullable();
            $table->unsignedInteger('max_holding_time')->nullable();
            // detected | approved | rejected | executed | expired | closed
            $table->string('status', 16)->default('detected');
            $table->json('reasons')->nullable();
            $table->json('risk_flags')->nullable();
            $table->unsignedBigInteger('detected_at_ms');
            $table->timestamps();

            $table->index(['strategy_id', 'created_at']);
            $table->index(['symbol', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_signals');
    }
};
