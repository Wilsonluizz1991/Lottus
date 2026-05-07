<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LottusMainStrategyPerformance extends Model
{
    protected $table = 'lottus_main_strategy_performance';

    protected $fillable = [
        'concurso',
        'strategy_name',
        'jogos',
        'raw14',
        'raw15',
        'selected14',
        'selected15',
        'near15',
        'loss14',
        'loss15',
        'elite_score',
        'payload_json',
    ];

    protected $casts = [
        'concurso' => 'integer',
        'jogos' => 'integer',
        'raw14' => 'integer',
        'raw15' => 'integer',
        'selected14' => 'integer',
        'selected15' => 'integer',
        'near15' => 'integer',
        'loss14' => 'integer',
        'loss15' => 'integer',
        'elite_score' => 'float',
        'payload_json' => 'array',
    ];
}
