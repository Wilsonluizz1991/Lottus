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
        'attempts' => 26000,
        'target_candidates' => 2200,
        'elite' => [
            'enabled' => true,
            'attempts' => 18000,
            'target_candidates' => 1800,
            'deterministic' => [
                'enabled' => true,
                'limit' => 900,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Production Entropy
    |--------------------------------------------------------------------------
    |
    | O motor principal nao deve depender de RNG solto em producao. A entropia
    | controlada torna a geracao auditavel e reproduzivel no backtest.
    |
    */

    'production_entropy' => [
        'enabled' => true,
        'base_seed' => 20260506,
        'package_profile' => 10,
        'email_variation' => false,
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
