<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motor_learning_logs', function (Blueprint $table) {
            $table->id();

            $table->string('engine')->index();
            $table->string('strategy')->nullable()->index();

            $table->unsignedInteger('concurso')->index();
            $table->unsignedTinyInteger('quantidade_dezenas')->index();

            $table->json('base_numbers');
            $table->json('resultado_numbers');

            $table->json('hits')->nullable();
            $table->json('misses')->nullable();

            $table->decimal('prediction_error', 10, 4)->default(0);
            $table->decimal('proximity_score', 10, 4)->default(0);

            $table->json('metrics')->nullable();

            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index([
                'concurso',
                'quantidade_dezenas'
            ], 'motor_learning_concurso_qtd_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motor_learning_logs');
    }
};