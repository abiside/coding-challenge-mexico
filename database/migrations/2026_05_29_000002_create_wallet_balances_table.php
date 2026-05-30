<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_balances', function (Blueprint $table) {
            $table->id();
            $table->string('exchange');
            $table->string('asset');
            $table->decimal('available', 36, 18)->default(0);
            $table->decimal('locked', 36, 18)->default(0);
            // Optimistic lock counter mutado solo por el single-writer.
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();

            $table->unique(['exchange', 'asset']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_balances');
    }
};
