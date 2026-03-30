<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lottus_pedidos', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->string('email');
            $table->foreignId('concurso_base_id')->constrained('lotofacil_concursos')->cascadeOnDelete();

            $table->decimal('valor', 10, 2)->default(2.00);
            $table->json('jogo');
            $table->json('analise')->nullable();

            $table->string('status')->default('aguardando_pagamento'); // aguardando_pagamento, pago, expirado, cancelado
            $table->string('gateway')->default('mercadopago');
            $table->string('external_reference')->unique();
            $table->string('gateway_preference_id')->nullable();
            $table->text('payment_url')->nullable();
            $table->text('sandbox_payment_url')->nullable();

            $table->string('payment_id')->nullable();
            $table->string('payment_status')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lottus_pedidos');
    }
};