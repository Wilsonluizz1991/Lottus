<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LottusLearningSnapshot extends Model
{
    public const VALIDATION_PENDING = 'pending';
    public const VALIDATION_PROMOTED = 'promoted';
    public const VALIDATION_REJECTED = 'rejected';
    public const VALIDATION_FAILED = 'failed';
    public const VALIDATION_SKIPPED = 'skipped';

    public const STRATEGY_RANKING = 'ranking';
    public const STRATEGY_CANDIDATES = 'candidates';
    public const STRATEGY_COMBINED = 'combined';

    protected $table = 'lottus_learning_snapshots';

    protected $fillable = [
        'concurso',
        'target_concurso',
        'calibration_version',
        'strategy_weights',
        'structure_bias',
        'pair_bias',
        'raw_elite_protection',
        'metrics_json',
        'validation_status',
        'promoted_strategy',
        'validated_at',
        'promotion_score',
        'validation_metrics',
    ];

    protected $casts = [
        'concurso' => 'integer',
        'target_concurso' => 'integer',
        'calibration_version' => 'integer',
        'strategy_weights' => 'array',
        'structure_bias' => 'array',
        'pair_bias' => 'array',
        'raw_elite_protection' => 'array',
        'metrics_json' => 'array',
        'validated_at' => 'datetime',
        'promotion_score' => 'float',
        'validation_metrics' => 'array',
    ];
}
