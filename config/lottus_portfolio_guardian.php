<?php

return [

    'stop_conditions' => [
        'target_average_loss' => 1.0,
        'minimum_selected_13' => 3,
        'minimum_selected_14' => 1,
        'allow_stop_without_14' => false,
    ],

    'weights' => [
        'selected_15' => 10000000,
        'selected_14' => 1500000,
        'selected_13' => 120000,
        'selected_12' => 3000,
        'selected_11' => 150,

        'raw_14_preserved' => 400000,
        'raw_13_preserved' => 80000,

        'raw_14_lost' => -800000,
        'raw_13_lost' => -120000,

        'loss_penalty' => -25000,
        'average_loss_penalty' => -150000,
    ],

    'search_space' => [

        'portfolio_expansion.raw_weight' => [
            0.94,
            0.955,
            0.965,
            0.975,
            0.985,
            0.995,
        ],

        'portfolio_expansion.diversity_weight' => [
            0.000,
            0.002,
            0.004,
            0.006,
            0.010,
        ],

        'portfolio_expansion.coverage_weight' => [
            0.000,
            0.001,
            0.003,
            0.005,
        ],

        'portfolio_expansion.core_bonus_multiplier' => [
            1.10,
            1.20,
            1.30,
            1.45,
            1.60,
        ],

        'core_bonus.overlap_14' => [
            320.0,
            500.0,
            700.0,
            900.0,
            1200.0,
        ],

        'core_bonus.overlap_13' => [
            240.0,
            320.0,
            450.0,
            650.0,
            850.0,
        ],

        'core_bonus.overlap_12' => [
            80.0,
            120.0,
            180.0,
            240.0,
        ],

        'clone_penalty.overlap_14' => [
            0.0,
            0.5,
            1.0,
            2.0,
            4.0,
        ],

        'clone_penalty.overlap_13' => [
            0.0,
            0.2,
            0.5,
            1.0,
        ],

        'clone_penalty.low_overlap_penalty' => [
            6.0,
            10.0,
            16.0,
            24.0,
            32.0,
        ],

        'elite_lock.absolute_locked_limit' => [
            2,
            3,
            4,
            5,
        ],

        'elite_lock.elite_threshold' => [
            0.82,
            0.86,
            0.88,
            0.90,
            0.92,
        ],

        'elite_lock.elite_limit' => [
            2,
            3,
            4,
        ],

        'elite_lock.late_elite_threshold' => [
            0.82,
            0.86,
            0.90,
            0.94,
        ],

        'elite_lock.late_elite_multiplier' => [
            50.0,
            100.0,
            180.0,
            300.0,
        ],

        'elite_survival.limit' => [
            3,
            4,
            5,
        ],

        'elite_survival.original_ranking_limit' => [
            2,
            3,
            4,
        ],

        'elite_survival.raw_ranking_limit' => [
            2,
            3,
            4,
        ],

        'elite_survival.consensus_limit' => [
            1,
            2,
            3,
        ],

        'cluster_elite_lock.enabled' => [
            true,
            false,
        ],

        'cluster_elite_lock.limit' => [
            0,
            1,
            2,
        ],

        'cluster_elite_lock.min_overlap' => [
            11,
            12,
            13,
        ],

        'controlled_diversity.enabled' => [
            true,
        ],

        'controlled_diversity.max_overlap_14_count' => [
            1,
            2,
        ],

        'controlled_diversity.max_overlap_13_count' => [
            2,
            3,
            4,
        ],

        'controlled_diversity.default_max_overlap_after_two' => [
            12,
            13,
            14,
        ],

        'controlled_diversity.critical_raw_threshold' => [
            1.00,
            1.01,
            1.02,
            1.03,
        ],

        'elite_override.threshold' => [
            0.90,
            0.94,
            0.965,
            0.985,
        ],

        'elite_override.score_gate' => [
            0.70,
            0.75,
            0.80,
            0.85,
        ],

        'elite_override.extreme_gate' => [
            0.70,
            0.75,
            0.78,
            0.82,
        ],

        'elite_override.stat_gate' => [
            0.65,
            0.70,
            0.75,
            0.80,
        ],

        'elite_override.multiplier' => [
            9999.0,
            25000.0,
            50000.0,
        ],

    ],

];