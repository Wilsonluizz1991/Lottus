<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motor_learning_weights', function (Blueprint $table) {
            $table->id();

            $table->string('engine')->index();
            $table->string('strategy')->nullable()->index();

            $table->json('weights');

            $table->decimal('learning_rate', 10, 6)->default(0.015000);

            $table->unsignedInteger('samples')->default(0);

            $table->unsignedInteger('last_concurso')->nullable();

            $table->decimal('last_error', 10, 4)->nullable();
            $table->decimal('last_score', 10, 4)->nullable();

            $table->timestamp('updated_by_learning_at')->nullable();

            $table->timestamps();

            $table->unique([
                'engine',
                'strategy'
            ], 'motor_learning_engine_strategy_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motor_learning_weights');
    }
};