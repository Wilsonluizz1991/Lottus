<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fechamento Inteligente Lottus
    |--------------------------------------------------------------------------
    |
    | Produto premium separado do motor principal de 15 dezenas.
    | O usuário escolhe apenas o tamanho do fechamento.
    | O motor escolhe as dezenas e gera combinações otimizadas.
    |
    */

    'enabled' => true,

    /*
    |--------------------------------------------------------------------------
    | Quantidade permitida de dezenas
    |--------------------------------------------------------------------------
    */

    'min_dezenas' => 16,
    'max_dezenas' => 20,

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    */

    'prices' => [
        16 => 7.90,
        17 => 11.90,
        18 => 17.90,
        19 => 27.90,
        20 => 39.90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Quantidade final de jogos entregues
    |--------------------------------------------------------------------------
    |
    | Cada fechamento gera jogos finais de 15 dezenas.
    | O número abaixo representa quantos jogos otimizados serão entregues
    | para cada tipo de fechamento.
    |
    */

    'output_games' => [
        16 => 16,
        17 => 24,
        18 => 36,
        19 => 60,
        20 => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pool interno
    |--------------------------------------------------------------------------
    |
    | Quantidade de candidatos internos usados para selecionar as dezenas fortes.
    |
    */

    'candidate_pool' => [
        'attempts' => 12000,
        'target_candidates' => 1200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pesos para seleção das dezenas fortes
    |--------------------------------------------------------------------------
    */

    'number_selection_weights' => [
        'frequency' => 0.24,
        'delay' => 0.20,
        'cycle' => 0.22,
        'correlation' => 0.24,
        'last_draw_presence' => 0.10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pesos para score das combinações finais
    |--------------------------------------------------------------------------
    */

    'combination_score_weights' => [
        'base_score' => 0.42,
        'coverage' => 0.22,
        'correlation' => 0.18,
        'structure' => 0.10,
        'diversity' => 0.08,
    ],

    /*
    |--------------------------------------------------------------------------
    | Limites estruturais suaves
    |--------------------------------------------------------------------------
    |
    | Não são filtros assassinos. São apenas bônus/penalidades leves.
    |
    */

    'soft_structure' => [
        'sum_min' => 165,
        'sum_max' => 220,
        'odd_min' => 6,
        'odd_max' => 9,
        'repeat_min' => 7,
        'repeat_max' => 12,
        'max_sequence' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redução inteligente
    |--------------------------------------------------------------------------
    |
    | Controla como o fechamento reduz o universo combinatório final.
    |
    */

    'reducer' => [
        'max_internal_combinations' => 16000,
        'min_overlap_between_games' => 9,
        'max_overlap_between_games' => 14,
        'elite_overlap_bonus_min' => 11,
    ],

    /*
    |--------------------------------------------------------------------------
    | Snapshots de aprendizado adaptativo
    |--------------------------------------------------------------------------
    |
    | O fechamento pode ler a calibracao gerada apos cada novo concurso. Por
    | seguranca, os sinais aprendidos ficam inicialmente em modo sombra:
    | carregados, logados e disponiveis para A/B, mas sem alterar o ranking
    | nem inserir candidatos enquanto nao provarem ganho contra o baseline.
    |
    */

    'learning_snapshots' => [
        'enabled' => true,
        'use_promoted' => true,
        'allow_unvalidated_effects' => false,
        'affect_ranking' => false,
        'generate_candidates' => false,
        'validation_mode' => false,
        'validation_snapshot_id' => null,
        'validation' => [
            'enabled' => true,
            'quantidades' => [18],
            'bases' => 48,
            'strategies' => ['ranking', 'candidates', 'combined'],
            'window' => 10,
            'min_validations' => 3,
            'min_win_rate' => 0.6,
            'min_elite_delta' => 1,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Metadata comercial
    |--------------------------------------------------------------------------
    */

    'product' => [
        'name' => 'Fechamento Inteligente Lottus',
        'description' => 'Fechamento matemático orientado pelo motor estatístico da Lottus.',
        'engine_version' => 'fechamento-v1',
    ],

];
