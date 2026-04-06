<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lottus_pedidos', function (Blueprint $table) {
            $table->foreignId('cupom_id')->nullable()->after('concurso_base_id')->constrained('cupons')->nullOnDelete();
            $table->string('cupom_codigo')->nullable()->after('cupom_id');
            $table->decimal('subtotal', 10, 2)->default(0)->after('valor');
            $table->decimal('desconto', 10, 2)->default(0)->after('subtotal');
            $table->decimal('valor_original', 10, 2)->default(0)->after('desconto');
        });
    }

    public function down(): void
    {
        Schema::table('lottus_pedidos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cupom_id');
            $table->dropColumn([
                'cupom_codigo',
                'subtotal',
                'desconto',
                'valor_original',
            ]);
        });
    }
};