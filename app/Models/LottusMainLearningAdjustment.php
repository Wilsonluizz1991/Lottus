<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LottusMainLearningAdjustment extends Model
{
    protected $table = 'lottus_main_learning_adjustments';

    protected $fillable = [
        'snapshot_id',
        'type',
        'key',
        'old_value',
        'new_value',
        'delta',
        'reason',
        'confidence',
    ];

    protected $casts = [
        'snapshot_id' => 'integer',
        'old_value' => 'float',
        'new_value' => 'float',
        'delta' => 'float',
        'confidence' => 'float',
    ];
}
