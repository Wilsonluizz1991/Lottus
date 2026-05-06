<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lottus_learning_snapshots', function (Blueprint $table) {
            $table->string('validation_status')->default('pending')->index()->after('metrics_json');
            $table->string('promoted_strategy')->nullable()->index()->after('validation_status');
            $table->timestamp('validated_at')->nullable()->after('promoted_strategy');
            $table->decimal('promotion_score', 14, 4)->nullable()->after('validated_at');
            $table->json('validation_metrics')->nullable()->after('promotion_score');
        });
    }

    public function down(): void
    {
        Schema::table('lottus_learning_snapshots', function (Blueprint $table) {
            $table->dropColumn([
                'validation_status',
                'promoted_strategy',
                'validated_at',
                'promotion_score',
                'validation_metrics',
            ]);
        });
    }
};
