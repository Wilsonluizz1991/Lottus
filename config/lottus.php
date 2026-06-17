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
    | Commercial Generation Guardrails
    |--------------------------------------------------------------------------
    |
    | O fluxo web roda dentro do PHP-FPM e precisa caber na memoria da instancia
    | de producao. Backtests podem explorar universos muito maiores, mas a venda
    | real deve ranquear um nucleo elite compacto para evitar 502/504.
    |
    */

    'commercial_generation' => [
        'enabled' => true,
        'max_baseline_candidates' => 450,
        'max_elite_candidates' => 450,
        'max_family_candidates' => 650,
        'max_rank_candidates' => 1400,
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
