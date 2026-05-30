<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_events', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('level', 16)->default('info');
            $table->string('symbol')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_events');
    }
};
