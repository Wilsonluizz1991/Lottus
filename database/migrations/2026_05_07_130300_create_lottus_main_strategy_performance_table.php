<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lottus_main_strategy_performance', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('concurso')->index();
            $table->string('strategy_name')->index();
            $table->unsignedSmallInteger('jogos')->default(10)->index();
            $table->unsignedSmallInteger('raw14')->default(0);
            $table->unsignedSmallInteger('raw15')->default(0);
            $table->unsignedSmallInteger('selected14')->default(0);
            $table->unsignedSmallInteger('selected15')->default(0);
            $table->unsignedSmallInteger('near15')->default(0);
            $table->unsignedSmallInteger('loss14')->default(0);
            $table->unsignedSmallInteger('loss15')->default(0);
            $table->decimal('elite_score', 14, 6)->default(0);
            $table->json('payload_json')->nullable();
            $table->timestamps();

            $table->unique(['concurso', 'strategy_name', 'jogos'], 'main_strategy_perf_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lottus_main_strategy_performance');
    }
};
