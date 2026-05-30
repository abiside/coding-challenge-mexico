<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->foreignId('strategy_id')
                ->nullable()
                ->after('user_id')
                ->constrained('arbitrage_strategies')
                ->nullOnDelete();
            $table->index(['strategy_id', 'created_at']);
        });

        Schema::table('trades', function (Blueprint $table) {
            $table->foreignId('strategy_id')
                ->nullable()
                ->after('user_id')
                ->constrained('arbitrage_strategies')
                ->nullOnDelete();
            $table->index(['strategy_id', 'created_at']);
        });

        Schema::table('bot_events', function (Blueprint $table) {
            $table->foreignId('strategy_id')
                ->nullable()
                ->after('user_id')
                ->constrained('arbitrage_strategies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropForeign(['strategy_id']);
            $table->dropIndex(['strategy_id', 'created_at']);
            $table->dropColumn('strategy_id');
        });

        Schema::table('trades', function (Blueprint $table) {
            $table->dropForeign(['strategy_id']);
            $table->dropIndex(['strategy_id', 'created_at']);
            $table->dropColumn('strategy_id');
        });

        Schema::table('bot_events', function (Blueprint $table) {
            $table->dropForeign(['strategy_id']);
            $table->dropColumn('strategy_id');
        });
    }
};
