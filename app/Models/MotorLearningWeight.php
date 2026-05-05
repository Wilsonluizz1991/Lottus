<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MotorLearningWeight extends Model
{
    protected $table = 'motor_learning_weights';

    protected $fillable = [
        'engine',
        'strategy',
        'weights',
        'learning_rate',
        'samples',
        'last_concurso',
        'last_error',
        'last_score',
        'updated_by_learning_at',
    ];

    protected $casts = [
        'weights' => 'array',
        'learning_rate' => 'float',
        'samples' => 'integer',
        'last_concurso' => 'integer',
        'last_error' => 'float',
        'last_score' => 'float',
        'updated_by_learning_at' => 'datetime',
    ];
}