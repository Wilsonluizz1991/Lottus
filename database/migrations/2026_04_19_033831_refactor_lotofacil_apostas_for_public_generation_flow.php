<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lotofacil_apostas', function (Blueprint $table) {
            $table->uuid('token_lote')->after('email')->index();
        });
    }

    public function down(): void
    {
        Schema::table('lotofacil_apostas', function (Blueprint $table) {
            $table->dropColumn('token_lote');
        });
    }
};