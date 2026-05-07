<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LottusMainLearningSnapshot extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROMOTED = 'promoted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';

    protected $table = 'lottus_main_learning_snapshots';

    protected $fillable = [
        'concurso_base',
        'target_concurso',
        'status',
        'version',
        'payload_json',
        'metrics_json',
        'confidence',
    ];

    protected $casts = [
        'concurso_base' => 'integer',
        'target_concurso' => 'integer',
        'version' => 'integer',
        'payload_json' => 'array',
        'metrics_json' => 'array',
        'confidence' => 'float',
    ];
}
