<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lottus_learning_snapshots', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('concurso')->index();
            $table->unsignedInteger('target_concurso')->index();
            $table->unsignedInteger('calibration_version')->default(1);

            $table->json('strategy_weights')->nullable();
            $table->json('structure_bias')->nullable();
            $table->json('pair_bias')->nullable();
            $table->json('raw_elite_protection')->nullable();
            $table->json('metrics_json')->nullable();

            $table->timestamps();

            $table->unique(['concurso', 'calibration_version'], 'learning_snapshots_concurso_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lottus_learning_snapshots');
    }
};
