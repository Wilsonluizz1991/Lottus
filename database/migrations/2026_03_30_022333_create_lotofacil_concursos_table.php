<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotofacil_concursos', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('concurso')->unique();
            $table->date('data_sorteio');

            $table->unsignedTinyInteger('bola1');
            $table->unsignedTinyInteger('bola2');
            $table->unsignedTinyInteger('bola3');
            $table->unsignedTinyInteger('bola4');
            $table->unsignedTinyInteger('bola5');
            $table->unsignedTinyInteger('bola6');
            $table->unsignedTinyInteger('bola7');
            $table->unsignedTinyInteger('bola8');
            $table->unsignedTinyInteger('bola9');
            $table->unsignedTinyInteger('bola10');
            $table->unsignedTinyInteger('bola11');
            $table->unsignedTinyInteger('bola12');
            $table->unsignedTinyInteger('bola13');
            $table->unsignedTinyInteger('bola14');
            $table->unsignedTinyInteger('bola15');

            $table->timestamps();

            $table->index('data_sorteio');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lotofacil_concursos');
    }
};