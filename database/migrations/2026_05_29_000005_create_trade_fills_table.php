<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_fills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained('trades')->cascadeOnDelete();
            $table->string('side', 8);
            $table->string('exchange');
            $table->string('symbol');
            $table->decimal('base_volume', 36, 18);
            $table->decimal('price', 36, 18);
            $table->decimal('notional', 36, 18);
            $table->decimal('fee', 36, 18)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_fills');
    }
};
