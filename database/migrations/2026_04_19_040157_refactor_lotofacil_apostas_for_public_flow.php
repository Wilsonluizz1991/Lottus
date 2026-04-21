<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lotofacil_apostas', function (Blueprint $table) {
            // 1) remover FK de user_id (se existir)
            if (Schema::hasColumn('lotofacil_apostas', 'user_id')) {
                try {
                    $table->dropForeign(['user_id']);
                } catch (\Throwable $e) {
                    // ignora se não existir a constraint
                }
            }
        });

        Schema::table('lotofacil_apostas', function (Blueprint $table) {
            // 2) remover coluna user_id (se existir)
            if (Schema::hasColumn('lotofacil_apostas', 'user_id')) {
                $table->dropColumn('user_id');
            }

            // 3) adicionar email (se não existir)
            if (! Schema::hasColumn('lotofacil_apostas', 'email')) {
                $table->string('email')->after('id');
                $table->index('email');
            }

            // 4) adicionar token_lote (se não existir)
            if (! Schema::hasColumn('lotofacil_apostas', 'token_lote')) {
                $table->uuid('token_lote')->after('email');
                $table->index('token_lote');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lotofacil_apostas', function (Blueprint $table) {
            // remover colunas novas (se existirem)
            if (Schema::hasColumn('lotofacil_apostas', 'token_lote')) {
                $table->dropColumn('token_lote');
            }

            if (Schema::hasColumn('lotofacil_apostas', 'email')) {
                $table->dropColumn('email');
            }

            // recriar user_id
            if (! Schema::hasColumn('lotofacil_apostas', 'user_id')) {
                $table->foreignId('user_id')->after('id')->constrained()->cascadeOnDelete();
            }
        });
    }
};