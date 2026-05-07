<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lottus_main_learning_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('concurso_base')->index();
            $table->unsignedInteger('target_concurso')->index();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('version')->default(1);
            $table->json('payload_json')->nullable();
            $table->json('metrics_json')->nullable();
            $table->decimal('confidence', 8, 6)->default(0);
            $table->timestamps();

            $table->unique(['concurso_base', 'version'], 'main_learning_snapshots_base_version_unique');
            $table->index(['target_concurso', 'status'], 'main_learning_snapshots_target_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lottus_main_learning_snapshots');
    }
};
