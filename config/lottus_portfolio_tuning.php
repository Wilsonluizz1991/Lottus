<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lottus Portfolio Tuning
    |--------------------------------------------------------------------------
    */

    'default' => [

    'portfolio_expansion' => [
        'raw_weight' => 0.965,
        'diversity_weight' => 0.002,
        'coverage_weight' => 0.005,
        'core_bonus_multiplier' => 1.6,
    ],

    'core_bonus' => [
        'overlap_14' => 500.0,
        'overlap_13' => 850.0,
        'overlap_12' => 80.0,
        'overlap_11' => 42.0,
        'overlap_10' => 0.0,
    ],

    'clone_penalty' => [
        'overlap_15' => 999.0,
        'overlap_14' => 0.0,
        'overlap_13' => 0.2,
        'low_overlap_limit' => 6,
        'low_overlap_penalty' => 32.0,
    ],

    'diversity' => [
        'average_distance_multiplier' => 5.5,
    ],

    'elite_lock' => [
        'absolute_locked_limit' => 4,
        'elite_threshold' => 0.86,
        'elite_limit' => 2,
        'late_elite_threshold' => 0.90,
        'late_elite_multiplier' => 300.0,
    ],

    'elite_survival' => [
        'limit' => 3,
        'minimum_candidates' => 20,
        'original_ranking_limit' => 2,
        'raw_ranking_limit' => 2,
        'consensus_limit' => 3,
    ],

    'elite_selection_audit' => [
        'enabled' => true,
        'top_raw_limit' => 10,
        'log_channel' => 'daily',
    ],

    'controlled_diversity' => [
        'enabled' => true,
        'max_overlap_14_count' => 2,
        'max_overlap_13_count' => 2,
        'default_max_overlap_after_two' => 12,
        'critical_raw_threshold' => 1.0,
        'critical_score_gate' => 0.90,
        'critical_extreme_gate' => 0.88,
        'critical_stat_gate' => 0.86,
    ],

    'cluster_elite_lock' => [
        'enabled' => false,
        'limit' => 0,
        'pool_limit' => 40,
        'min_overlap' => 11,
        'min_cluster_size' => 3,
    ],

    'elite_override' => [
        'threshold' => 0.90,
        'score_gate' => 0.75,
        'extreme_gate' => 0.70,
        'stat_gate' => 0.70,
        'multiplier' => 9999.0,
    ],

    'core_preservation' => [
        'min_overlap' => 12,
        'raw_boost' => 0.35,
        'max_candidates_multiplier' => 1,
    ],

    'near_winner' => [
        'score_threshold' => 0.90,
        'extreme_threshold' => 0.88,
        'stat_threshold' => 0.88,
    ],

    'raw_killer' => [
        'score_threshold' => 0.94,
        'extreme_threshold' => 0.92,
        'stat_threshold' => 0.90,
        'fallback_score_threshold' => 0.90,
        'fallback_extreme_threshold' => 0.88,
    ],

],

    /*
    |--------------------------------------------------------------------------
    | Presets
    |--------------------------------------------------------------------------
    */

    'presets' => [

        'hunt_14_plus' => [

            'portfolio_expansion' => [
                'raw_weight' => 0.965,
                'diversity_weight' => 0.005,
                'coverage_weight' => 0.003,
                'core_bonus_multiplier' => 1.35,
            ],

            'core_bonus' => [
                'overlap_14' => 700.0,
                'overlap_13' => 180.0,
                'overlap_12' => 30.0,
                'overlap_11' => 0.0,
                'overlap_10' => 0.0,
            ],

            'clone_penalty' => [
                'overlap_15' => 999.0,
                'overlap_14' => 1.0,
                'overlap_13' => 0.2,
                'low_overlap_limit' => 6,
                'low_overlap_penalty' => 30.0,
            ],

            'diversity' => [
                'average_distance_multiplier' => 4.0,
            ],

            'elite_lock' => [
                'absolute_locked_limit' => 3,
                'elite_threshold' => 0.94,
                'elite_limit' => 2,
                'late_elite_threshold' => 0.96,
                'late_elite_multiplier' => 180.0,
            ],

            'core_preservation' => [
                'min_overlap' => 13,
                'raw_boost' => 0.70,
                'max_candidates_multiplier' => 1,
            ],

            'near_winner' => [
                'score_threshold' => 0.90,
                'extreme_threshold' => 0.88,
                'stat_threshold' => 0.88,
            ],

            'raw_killer' => [
                'score_threshold' => 0.94,
                'extreme_threshold' => 0.92,
                'stat_threshold' => 0.90,
                'fallback_score_threshold' => 0.90,
                'fallback_extreme_threshold' => 0.88,
            ],

        ],

        'balanced_elite' => [

            'portfolio_expansion' => [
                'raw_weight' => 0.95,
                'diversity_weight' => 0.018,
                'coverage_weight' => 0.01,
                'core_bonus_multiplier' => 1.15,
            ],

            'core_bonus' => [
                'overlap_14' => 420.0,
                'overlap_13' => 240.0,
                'overlap_12' => 120.0,
                'overlap_11' => 35.0,
                'overlap_10' => 0.0,
            ],

            'clone_penalty' => [
                'overlap_15' => 999.0,
                'overlap_14' => 2.0,
                'overlap_13' => 0.7,
                'low_overlap_limit' => 6,
                'low_overlap_penalty' => 10.0,
            ],

            'diversity' => [
                'average_distance_multiplier' => 6.5,
            ],

            'elite_lock' => [
                'absolute_locked_limit' => 2,
                'elite_threshold' => 0.92,
                'elite_limit' => 1,
                'late_elite_threshold' => 0.94,
                'late_elite_multiplier' => 100.0,
            ],

            'core_preservation' => [
                'min_overlap' => 12,
                'raw_boost' => 0.35,
                'max_candidates_multiplier' => 1,
            ],

            'near_winner' => [
                'score_threshold' => 0.82,
                'extreme_threshold' => 0.80,
                'stat_threshold' => 0.82,
            ],

            'raw_killer' => [
                'score_threshold' => 0.90,
                'extreme_threshold' => 0.88,
                'stat_threshold' => 0.86,
                'fallback_score_threshold' => 0.86,
                'fallback_extreme_threshold' => 0.84,
            ],

        ],

        'guardian_14_baseline' => [

            'portfolio_expansion' => [
                'raw_weight' => 0.965,
                'diversity_weight' => 0.002,
                'coverage_weight' => 0.005,
                'core_bonus_multiplier' => 1.6,
            ],

            'core_bonus' => [
                'overlap_14' => 500.0,
                'overlap_13' => 850.0,
                'overlap_12' => 80.0,
                'overlap_11' => 42.0,
                'overlap_10' => 0.0,
            ],

            'clone_penalty' => [
                'overlap_15' => 999.0,
                'overlap_14' => 0.0,
                'overlap_13' => 0.2,
                'low_overlap_limit' => 6,
                'low_overlap_penalty' => 32.0,
            ],

            'diversity' => [
                'average_distance_multiplier' => 5.5,
            ],

            'elite_lock' => [
                'absolute_locked_limit' => 4,
                'elite_threshold' => 0.86,
                'elite_limit' => 2,
                'late_elite_threshold' => 0.90,
                'late_elite_multiplier' => 300.0,
            ],

            'elite_survival' => [
                'limit' => 3,
                'minimum_candidates' => 20,
                'original_ranking_limit' => 2,
                'raw_ranking_limit' => 2,
                'consensus_limit' => 3,
            ],

            'elite_selection_audit' => [
                'enabled' => true,
                'top_raw_limit' => 10,
                'log_channel' => 'daily',
            ],

            'controlled_diversity' => [
                'enabled' => true,
                'max_overlap_14_count' => 2,
                'max_overlap_13_count' => 2,
                'default_max_overlap_after_two' => 12,
                'critical_raw_threshold' => 1.0,
                'critical_score_gate' => 0.90,
                'critical_extreme_gate' => 0.88,
                'critical_stat_gate' => 0.86,
            ],

            'cluster_elite_lock' => [
                'enabled' => false,
                'limit' => 0,
                'pool_limit' => 40,
                'min_overlap' => 11,
                'min_cluster_size' => 3,
            ],

            'elite_override' => [
                'threshold' => 0.90,
                'score_gate' => 0.75,
                'extreme_gate' => 0.70,
                'stat_gate' => 0.70,
                'multiplier' => 9999.0,
            ],

            'core_preservation' => [
                'min_overlap' => 12,
                'raw_boost' => 0.35,
                'max_candidates_multiplier' => 1,
            ],

            'near_winner' => [
                'score_threshold' => 0.90,
                'extreme_threshold' => 0.88,
                'stat_threshold' => 0.88,
            ],

            'raw_killer' => [
                'score_threshold' => 0.94,
                'extreme_threshold' => 0.92,
                'stat_threshold' => 0.90,
                'fallback_score_threshold' => 0.90,
                'fallback_extreme_threshold' => 0.88,
            ],

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Search Space
    |--------------------------------------------------------------------------
    */

    'search_space' => [

        'portfolio_expansion.raw_weight' => [
            0.930,
            0.940,
            0.948,
            0.955,
            0.965,
        ],

        'portfolio_expansion.diversity_weight' => [
            0.010,
            0.015,
            0.018,
            0.020,
            0.025,
        ],

        'portfolio_expansion.coverage_weight' => [
            0.005,
            0.008,
            0.010,
            0.012,
            0.015,
        ],

        'portfolio_expansion.core_bonus_multiplier' => [
            1.00,
            1.08,
            1.12,
            1.18,
            1.25,
        ],

        'core_bonus.overlap_14' => [
            260.0,
            320.0,
            380.0,
            440.0,
            520.0,
        ],

        'core_bonus.overlap_13' => [
            160.0,
            200.0,
            240.0,
            280.0,
            320.0,
        ],

        'core_bonus.overlap_12' => [
            80.0,
            100.0,
            120.0,
            140.0,
            160.0,
        ],

        'core_bonus.overlap_11' => [
            20.0,
            28.0,
            35.0,
            42.0,
            50.0,
        ],

        'clone_penalty.overlap_14' => [
            1.0,
            2.0,
            4.0,
            8.0,
        ],

        'clone_penalty.overlap_13' => [
            0.3,
            0.5,
            0.7,
            1.0,
            2.0,
        ],

        'clone_penalty.low_overlap_limit' => [
            5,
            6,
            7,
        ],

        'clone_penalty.low_overlap_penalty' => [
            6.0,
            8.0,
            10.0,
            14.0,
            22.0,
        ],

        'diversity.average_distance_multiplier' => [
            5.5,
            6.5,
            7.0,
            7.5,
            8.0,
        ],

        'elite_lock.elite_threshold' => [
            0.88,
            0.90,
            0.92,
            0.94,
        ],

        'elite_lock.late_elite_threshold' => [
            0.90,
            0.92,
            0.94,
            0.96,
        ],

        'elite_lock.late_elite_multiplier' => [
            50.0,
            75.0,
            100.0,
            125.0,
        ],

        'core_preservation.min_overlap' => [
            12,
        ],

        'core_preservation.raw_boost' => [
            0.20,
            0.35,
            0.50,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Scoring
    |--------------------------------------------------------------------------
    */

    'scoring' => [
        'faixa_15' => 1000000,
        'faixa_14' => 250000,
        'faixa_13' => 25000,
        'faixa_12' => 1500,
        'faixa_11' => 100,
        'raw_14_preserved_bonus' => 50000,
        'raw_13_preserved_bonus' => 10000,
        'loss_penalty' => 750,
    ],

];