<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulated_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('strategy_signal_id')->nullable();
            $table->string('algorithm', 48);
            $table->string('symbol');
            // long | short
            $table->string('side', 8);
            $table->decimal('entry_price', 36, 18);
            $table->decimal('exit_price', 36, 18)->nullable();
            $table->decimal('size', 36, 18);
            $table->decimal('notional', 36, 18)->default(0);
            $table->decimal('leverage', 8, 2)->default(1);
            $table->decimal('take_profit', 36, 18)->nullable();
            $table->decimal('stop_loss', 36, 18)->nullable();
            $table->decimal('gross_pnl', 36, 18)->default(0);
            $table->decimal('fees', 36, 18)->default(0);
            $table->decimal('funding_fee', 36, 18)->default(0);
            $table->decimal('slippage', 36, 18)->default(0);
            $table->decimal('net_pnl', 36, 18)->default(0);
            // open | closed | stopped_out | take_profit_hit | expired | liquidated_simulated
            $table->string('status', 24)->default('open');
            $table->string('open_reason', 48)->nullable();
            $table->string('close_reason', 48)->nullable();
            $table->unsignedBigInteger('opened_at_ms');
            $table->unsignedBigInteger('closed_at_ms')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamps();

            $table->index(['strategy_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['symbol', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulated_positions');
    }
};
