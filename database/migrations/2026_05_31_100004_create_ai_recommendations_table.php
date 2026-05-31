<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_recommendations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('strategy_id')->nullable()->index();
            // market_summary | parameter_suggestion | alert | post_mortem
            $table->string('type', 32)->default('market_summary');
            $table->text('summary')->nullable();
            $table->json('payload')->nullable();
            // info | warning | critical
            $table->string('severity', 12)->default('info');
            // active | dismissed | applied
            $table->string('status', 16)->default('active');
            // llm | degraded
            $table->string('source', 16)->default('llm');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_recommendations');
    }
};
