<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lottus_main_learning_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')
                ->constrained('lottus_main_learning_snapshots')
                ->cascadeOnDelete();
            $table->string('type')->index();
            $table->string('key')->index();
            $table->decimal('old_value', 14, 8)->default(0);
            $table->decimal('new_value', 14, 8)->default(0);
            $table->decimal('delta', 14, 8)->default(0);
            $table->text('reason')->nullable();
            $table->decimal('confidence', 8, 6)->default(0);
            $table->timestamps();

            $table->index(['snapshot_id', 'type'], 'main_learning_adjustments_snapshot_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lottus_main_learning_adjustments');
    }
};
