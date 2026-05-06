<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PHP Runtime
    |--------------------------------------------------------------------------
    |
    | Ajustes para tuning pesado e backtests longos.
    |
    */

    'runtime' => [
        'memory_limit' => '1024M',
        'gc_enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pesos principais do motor
    |--------------------------------------------------------------------------
    */

    'weights' => [
        'frequency' => 0.25,
        'delay' => 0.25,
        'correlation' => 0.25,
        'cycle' => 0.25,
    ],

    /*
    |--------------------------------------------------------------------------
    | Generator
    |--------------------------------------------------------------------------
    */

    'generator' => [
        'attempts' => 14000,
        'target_candidates' => 1200,
        'elite' => [
            'enabled' => true,
            'attempts' => 14000,
            'target_candidates' => 1100,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Micro Variation
    |--------------------------------------------------------------------------
    |
    | Em produção, deve ficar desligado para garantir previsibilidade e
    | reprodutibilidade do motor.
    |
    */

    'micro_variation' => [
        'enabled' => false,
        'range' => 0.02,
    ],

];
