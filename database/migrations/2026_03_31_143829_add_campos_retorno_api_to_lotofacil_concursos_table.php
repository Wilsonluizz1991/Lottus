<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lotofacil_concursos', function (Blueprint $table) {
            // Premiação
            $table->unsignedInteger('ganhadores_15_acertos')->default(0)->after('bola15');
            $table->decimal('rateio_15_acertos', 15, 2)->default(0)->after('ganhadores_15_acertos');

            $table->unsignedInteger('ganhadores_14_acertos')->default(0)->after('rateio_15_acertos');
            $table->decimal('rateio_14_acertos', 15, 2)->default(0)->after('ganhadores_14_acertos');

            $table->unsignedInteger('ganhadores_13_acertos')->default(0)->after('rateio_14_acertos');
            $table->decimal('rateio_13_acertos', 15, 2)->default(0)->after('ganhadores_13_acertos');

            $table->unsignedInteger('ganhadores_12_acertos')->default(0)->after('rateio_13_acertos');
            $table->decimal('rateio_12_acertos', 15, 2)->default(0)->after('ganhadores_12_acertos');

            $table->unsignedInteger('ganhadores_11_acertos')->default(0)->after('rateio_12_acertos');
            $table->decimal('rateio_11_acertos', 15, 2)->default(0)->after('ganhadores_11_acertos');

            // Informações complementares
            $table->string('cidade_uf')->nullable()->after('rateio_11_acertos');
            $table->text('observacao')->nullable()->after('cidade_uf');

            // Valores financeiros
            $table->decimal('arrecadacao_total', 15, 2)->default(0)->after('observacao');
            $table->decimal('estimativa_premio', 15, 2)->default(0)->after('arrecadacao_total');
            $table->decimal('acumulado_15_acertos', 15, 2)->default(0)->after('estimativa_premio');
            $table->decimal('acumulado_sorteio_especial_lotofacil_independencia', 15, 2)->default(0)->after('acumulado_15_acertos');

            // Controle interno
            $table->boolean('informado_manualmente')->default(false)->after('acumulado_sorteio_especial_lotofacil_independencia');
        });
    }

    public function down(): void
    {
        Schema::table('lotofacil_concursos', function (Blueprint $table) {
            $table->dropColumn([
                'ganhadores_15_acertos',
                'rateio_15_acertos',
                'ganhadores_14_acertos',
                'rateio_14_acertos',
                'ganhadores_13_acertos',
                'rateio_13_acertos',
                'ganhadores_12_acertos',
                'rateio_12_acertos',
                'ganhadores_11_acertos',
                'rateio_11_acertos',
                'cidade_uf',
                'observacao',
                'arrecadacao_total',
                'estimativa_premio',
                'acumulado_15_acertos',
                'acumulado_sorteio_especial_lotofacil_independencia',
                'informado_manualmente',
            ]);
        });
    }
};