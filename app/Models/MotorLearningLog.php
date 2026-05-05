<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MotorLearningLog extends Model
{
    protected $table = 'motor_learning_logs';

    protected $fillable = [
        'engine',
        'strategy',
        'concurso',
        'quantidade_dezenas',
        'base_numbers',
        'resultado_numbers',
        'hits',
        'misses',
        'prediction_error',
        'proximity_score',
        'metrics',
        'processed_at',
    ];

    protected $casts = [
        'concurso' => 'integer',
        'quantidade_dezenas' => 'integer',
        'base_numbers' => 'array',
        'resultado_numbers' => 'array',
        'hits' => 'array',
        'misses' => 'array',
        'prediction_error' => 'float',
        'proximity_score' => 'float',
        'metrics' => 'array',
        'processed_at' => 'datetime',
    ];
}