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
        'attempts' => 8000,
        'target_candidates' => 800,
    ],

    'backtest' => [
        'oracle_mode' => env('LOTTUS_ORACLE_MODE', false),
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