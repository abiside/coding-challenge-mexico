<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mean_reversion_trades', function (Blueprint $table) {
            $table->unsignedBigInteger('strategy_id')->nullable()->after('user_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('mean_reversion_trades', function (Blueprint $table) {
            $table->dropColumn('strategy_id');
        });
    }
};
