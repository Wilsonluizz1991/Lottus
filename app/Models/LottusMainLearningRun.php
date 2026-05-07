<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LottusMainLearningRun extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $table = 'lottus_main_learning_runs';

    protected $fillable = [
        'concurso',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'baseline_metrics_json',
        'learned_metrics_json',
        'delta_metrics_json',
        'decision',
        'error_message',
    ];

    protected $casts = [
        'concurso' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'baseline_metrics_json' => 'array',
        'learned_metrics_json' => 'array',
        'delta_metrics_json' => 'array',
    ];
}
