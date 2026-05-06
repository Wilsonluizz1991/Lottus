<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LottusLearningRun extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $table = 'lottus_learning_runs';

    protected $fillable = [
        'concurso',
        'status',
        'calibration_version',
        'triggered_by',
        'started_at',
        'finished_at',
        'duration_ms',
        'adjustments_count',
        'metrics_json',
        'error_message',
    ];

    protected $casts = [
        'concurso' => 'integer',
        'calibration_version' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'adjustments_count' => 'integer',
        'metrics_json' => 'array',
    ];
}
