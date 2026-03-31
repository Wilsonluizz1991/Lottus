<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->string('external_reference')->unique();
            $table->string('order_code')->nullable()->index();

            $table->string('provider')->default('mercado_pago');
            $table->string('provider_preference_id')->nullable()->index();
            $table->string('provider_payment_id')->nullable()->index();
            $table->string('provider_collection_id')->nullable()->index();
            $table->string('provider_status')->nullable()->index();
            $table->string('provider_status_detail')->nullable();

            $table->string('payer_email')->nullable()->index();
            $table->string('payer_name')->nullable();

            $table->decimal('amount', 10, 2);
            $table->string('currency_id', 10)->default('BRL');

            $table->string('description')->nullable();
            $table->string('payment_method_id')->nullable();
            $table->string('payment_type_id')->nullable();

            $table->string('local_status')->default('created')->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->json('items')->nullable();
            $table->json('preference_payload')->nullable();
            $table->json('preference_response')->nullable();
            $table->json('last_payment_response')->nullable();

            $table->text('init_point')->nullable();
            $table->text('sandbox_init_point')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};