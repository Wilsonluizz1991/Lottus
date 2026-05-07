<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lottus_main_learning_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('concurso')->index();
            $table->string('status')->default('pending')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('baseline_metrics_json')->nullable();
            $table->json('learned_metrics_json')->nullable();
            $table->json('delta_metrics_json')->nullable();
            $table->string('decision')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['concurso'], 'main_learning_runs_concurso_unique');
            $table->index(['concurso', 'status'], 'main_learning_runs_concurso_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lottus_main_learning_runs');
    }
};
