<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lottus_learning_runs', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('concurso')->index();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('calibration_version')->default(1);
            $table->string('triggered_by')->default('auto')->index();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('adjustments_count')->default(0);
            $table->json('metrics_json')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->unique(['concurso', 'calibration_version'], 'learning_runs_concurso_version_unique');
            $table->index(['concurso', 'status'], 'learning_runs_concurso_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lottus_learning_runs');
    }
};
