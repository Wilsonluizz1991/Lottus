<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotofacil_apostas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('concurso_base_id')->constrained('lotofacil_concursos')->cascadeOnDelete();

            $table->date('data_esperada_sorteio');
            $table->json('dezenas');
            $table->decimal('score', 10, 2)->default(0);

            $table->unsignedTinyInteger('pares')->nullable();
            $table->unsignedTinyInteger('impares')->nullable();
            $table->unsignedSmallInteger('soma')->nullable();
            $table->unsignedTinyInteger('repetidas_ultimo_concurso')->nullable();
            $table->unsignedTinyInteger('quentes')->nullable();
            $table->unsignedTinyInteger('atrasadas')->nullable();

            $table->json('analise')->nullable();

            $table->timestamps();

            $table->index('data_esperada_sorteio');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lotofacil_apostas');
    }
};