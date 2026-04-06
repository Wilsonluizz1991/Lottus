<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cupons', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->string('nome')->nullable();
            $table->enum('tipo_desconto', ['percentual', 'fixo']);
            $table->decimal('valor_desconto', 10, 2);
            $table->decimal('valor_minimo_pedido', 10, 2)->nullable();
            $table->unsignedInteger('limite_total_uso')->nullable();
            $table->unsignedInteger('limite_uso_por_email')->nullable();
            $table->unsignedInteger('total_usos')->default(0);
            $table->boolean('ativo')->default(true);
            $table->timestamp('inicio_em')->nullable();
            $table->timestamp('expira_em')->nullable();
            $table->text('observacoes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cupons');
    }
};