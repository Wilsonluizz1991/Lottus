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
        'small_quantity_absolute_locked_limit' => 0,
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

        'dynamic_elite_portfolio' => [
        'enabled' => true,
        'max_quantity' => 100,
        'short_elite_coverage' => [
            'enabled' => false,
            'min_quantity' => 6,
            'max_quantity' => 10,
            'base_size' => 18,
            'top_pool_limit' => 120,
            'new_coverage_weight' => 25000.0,
            'limit' => 10,
        ],
        'rank_lattice' => [
            'enabled' => false,
            'anchor_window' => 18,
            'per_anchor' => 1,
            'elite_first_weight' => 0.35,
            'elite_weight' => 980.0,
            'near_15_weight' => 260.0,
            'ceiling_weight' => 150.0,
            'explosive_weight' => 135.0,
            'distance_penalty' => 450.0,
            'historical_14_bonus' => 90000.0,
            'historical_14_plus_bonus' => 30000.0,
            'historical_density_cap' => 120000.0,
            'family_expansion' => [
                'enabled' => false,
                'min_quantity' => 6,
                'per_anchor' => 1,
                'min_overlap' => 13,
                'max_rank_distance' => 220,
                'overlap_weight' => 500000.0,
                'elite_weight' => 620.0,
                'near_15_weight' => 180.0,
                'ceiling_weight' => 120.0,
                'rank_distance_penalty' => 420.0,
            ],
            'anchors_by_quantity' => [
                1 => [1122],
                2 => [1122, 1247],
                3 => [1122, 1247, 1273],
                4 => [1122, 1247, 1273, 1418],
                5 => [830, 1122, 1247, 1273, 1418],
                6 => [830, 1019, 1122, 1247, 1273, 1418],
                7 => [430, 830, 1019, 1122, 1247, 1273, 1418],
                8 => [430, 830, 1019, 1122, 1247, 1273, 1418, 1805],
                9 => [430, 830, 1019, 1122, 1247, 1273, 1418, 1805, 2159],
                10 => [430, 830, 1019, 1122, 1247, 1273, 1418, 1805, 2159, 2364],
            ],
            'slots_by_quantity' => [
                1 => 1,
                2 => 2,
                3 => 3,
                4 => 4,
                5 => 5,
                6 => 6,
                7 => 7,
                8 => 8,
                9 => 9,
                10 => 10,
            ],
            'strategy_boosts' => [
                'controlled_delay' => 920.0,
                'baseline_explosive' => 880.0,
                'elite_high_ceiling' => 860.0,
                'correlation_cluster' => 820.0,
                'explosive_hybrid' => 800.0,
                'historical_replay' => 760.0,
                'strategic_repeat' => 720.0,
                'anti_mean_high_ceiling' => 700.0,
            ],
        ],
        'historical_14_evidence' => [
            'enabled' => true,
            'rank_window' => 160,
            'anchor_window' => 4,
            'per_anchor' => 9,
            'anchor_ranks' => [
                82,
                1170,
                2105,
                54,
                62,
                112,
                128,
                198,
                346,
                562,
                888,
                1128,
                1363,
                1450,
                1762,
                2060,
                2180,
                2364,
            ],
            'strategy_priority' => [
                'historical_replay',
                'historical_elite_mutation',
                'deterministic_high_ceiling',
                'elite_high_ceiling',
                'explosive_hybrid',
                'correlation_cluster',
                'controlled_delay',
                'strategic_repeat',
                'anti_mean_high_ceiling',
                'baseline_explosive',
            ],
            'slots_by_quantity' => [
                1 => 1,
                2 => 1,
                3 => 2,
                4 => 2,
                5 => 2,
                6 => 1,
                7 => 1,
                8 => 1,
                9 => 1,
                10 => 1,
            ],
        ],
        'rank_probe' => [
            'enabled' => false,
            'anchor_window' => 4,
            'per_anchor' => 1,
            'anchor_ranks' => [
                82,
                1170,
                2105,
                2093,
                143,
                555,
                1508,
                2356,
                1826,
                57,
                69,
                112,
                198,
                346,
                562,
                888,
                1128,
                1363,
                1450,
                1762,
                2060,
                2180,
                2364,
            ],
            'slots_by_quantity' => [
                1 => 1,
                2 => 1,
                3 => 1,
                4 => 2,
                5 => 2,
                6 => 5,
                7 => 6,
                8 => 7,
                9 => 8,
                10 => 9,
            ],
        ],
        'conversion_sweep' => [
            'enabled' => true,
            'shortlist_quantity' => 10,
            'window' => 1000,
            'per_band' => 1,
            'family_neighbors_enabled' => true,
            'family_min_band' => 0.49,
            'family_window' => 220,
            'family_per_band' => 16,
            'family_radii' => [16, 32, 96, 64, 128, 192],
            'family_directions' => [1, -1],
            'family_cluster_radius_min' => 80,
            'family_cluster_window' => 12,
            'family_cluster_size' => 3,
            'strategies' => [
                'single_swap_sweep',
                'double_swap_sweep',
            ],
            'bands' => [
                0.02,
                0.16,
                0.25,
                0.40,
                0.49,
                0.54,
                0.62,
                0.74,
                0.86,
            ],
            'slots_by_quantity' => [
                1 => 1,
                2 => 2,
                3 => 3,
                4 => 4,
                5 => 5,
                6 => 6,
                7 => 7,
                8 => 8,
                9 => 9,
                10 => 10,
            ],
        ],
        'strategy_boosts' => [
                'double_swap_sweep' => 2200.0,
                'single_swap_sweep' => 2000.0,
                'deterministic_high_ceiling' => 950.0,
            'elite_high_ceiling' => 900.0,
            'explosive_hybrid' => 880.0,
            'historical_elite_mutation' => 860.0,
            'forced_pair_synergy_core' => 760.0,
            'correlation_cluster' => 740.0,
            'cycle_missing_rescue' => 680.0,
            'controlled_delay' => 640.0,
            'repeat_pressure_core' => 620.0,
            'strategic_repeat' => 600.0,
            'anti_mean_high_ceiling' => 560.0,
            'historical_replay' => 520.0,
            'baseline_explosive' => 260.0,
        ],
    ],

    'rank_band_exploration' => [
        'enabled' => true,
        'min_quantity' => 20,
        'slot_ratio' => 0.18,
        'max_slots' => 18,
        'window' => 8,
        'bands' => [
            0.08,
            0.14,
            0.20,
            0.28,
            0.36,
            0.46,
            0.58,
            0.72,
        ],
    ],

    'historical_peak_lock' => [
        'enabled' => false,
        'max_quantity' => 25,
        'limit' => 2,
        'rank_window' => 700,
        'min_historical_max_hits' => 14,
        'min_historical_14_plus' => 1,
    ],

    'short_high_ceiling_guard' => [
        'enabled' => true,
        'max_quantity' => 10,
        'limit' => 3,
        'rank_window' => 2600,
        'per_strategy_limit' => 1,
        'rank_sweep_enabled' => true,
        'rank_sweep_limit' => 4,
        'rank_sweep_per_target' => 3,
        'rank_sweep_window' => 14,
        'rank_sweep_targets' => [
            2180,
        ],
        'rank_sweep_bands' => [
            0.500,
        ],
        'rank_sweep_strategy_priority' => [
            'controlled_delay',
            'elite_high_ceiling',
            'correlation_cluster',
            'explosive_hybrid',
            'historical_replay',
            'strategic_repeat',
            'anti_mean_high_ceiling',
        ],
        'strategies' => [
            'elite_high_ceiling',
            'controlled_delay',
            'explosive_hybrid',
            'correlation_cluster',
            'anti_mean_high_ceiling',
            'historical_replay',
            'strategic_repeat',
        ],
        'strategy_boosts' => [
            'elite_high_ceiling' => 460.0,
            'controlled_delay' => 420.0,
            'explosive_hybrid' => 400.0,
            'correlation_cluster' => 360.0,
            'anti_mean_high_ceiling' => 340.0,
            'historical_replay' => 330.0,
            'strategic_repeat' => 300.0,
        ],
    ],

    'score_rank_guard' => [
        'enabled' => true,
        'min_quantity' => 1,
        'small_quantity_max' => 25,
        'small_quantity_min_slots' => 10,
        'slot_ratio' => 0.28,
        'max_slots' => 20,
        'window' => 2,
        'anchor_window' => 8,
        'small_quantity_anchor_slot_limit' => 7,
        'anchor_slot_limit' => 12,
        'per_band_limit' => 1,
        'late_band_threshold' => 0.50,
        'late_per_band_limit' => 2,
        'anchor_ranks' => [
            62,
            346,
            562,
            1450,
            1762,
            2060,
            2180,
            2364,
            383,
            738,
            842,
        ],
        'small_quantity_anchor_ranks' => [
            62,
            346,
            562,
            1450,
            1762,
            2060,
            2180,
            2364,
            383,
            738,
            842,
        ],
        'bands' => [
            0.035,
            0.070,
            0.110,
            0.150,
            0.190,
            0.230,
            0.270,
            0.319,
            0.390,
            0.500,
            0.615,
            0.702,
        ],
        'small_quantity_bands' => [
            0.166,
            0.234,
            0.236,
            0.244,
            0.319,
            0.615,
            0.702,
            0.035,
            0.070,
            0.110,
            0.150,
            0.190,
            0.230,
            0.270,
            0.390,
            0.500,
            0.850,
        ],
    ],

    'elite_selection_audit' => [
        'enabled' => true,
        'top_raw_limit' => 10,
        'log_channel' => 'single',
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

            'historical_peak_lock' => [
                'enabled' => false,
                'max_quantity' => 25,
                'limit' => 2,
                'rank_window' => 700,
                'min_historical_max_hits' => 14,
                'min_historical_14_plus' => 1,
            ],

            'short_high_ceiling_guard' => [
                'enabled' => true,
                'max_quantity' => 10,
                'limit' => 3,
                'rank_window' => 2600,
                'per_strategy_limit' => 1,
                'rank_sweep_enabled' => true,
                'rank_sweep_limit' => 4,
                'rank_sweep_per_target' => 3,
                'rank_sweep_window' => 14,
                'rank_sweep_targets' => [
                    2180,
                ],
                'rank_sweep_bands' => [
                    0.500,
                ],
                'rank_sweep_strategy_priority' => [
                    'controlled_delay',
                    'elite_high_ceiling',
                    'correlation_cluster',
                    'explosive_hybrid',
                    'historical_replay',
                    'strategic_repeat',
                    'anti_mean_high_ceiling',
                ],
                'strategies' => [
                    'elite_high_ceiling',
                    'controlled_delay',
                    'explosive_hybrid',
                    'correlation_cluster',
                    'anti_mean_high_ceiling',
                    'historical_replay',
                    'strategic_repeat',
                ],
                'strategy_boosts' => [
                    'elite_high_ceiling' => 460.0,
                    'controlled_delay' => 420.0,
                    'explosive_hybrid' => 400.0,
                    'correlation_cluster' => 360.0,
                    'anti_mean_high_ceiling' => 340.0,
                    'historical_replay' => 330.0,
                    'strategic_repeat' => 300.0,
                ],
            ],

            'score_rank_guard' => [
                'enabled' => true,
                'min_quantity' => 1,
                'small_quantity_max' => 25,
                'small_quantity_min_slots' => 10,
                'slot_ratio' => 0.28,
                'max_slots' => 20,
                'window' => 2,
                'anchor_window' => 8,
                'small_quantity_anchor_slot_limit' => 7,
                'anchor_slot_limit' => 12,
                'per_band_limit' => 1,
                'late_band_threshold' => 0.50,
                'late_per_band_limit' => 2,
                'anchor_ranks' => [
                    62,
                    346,
                    562,
                    1450,
                    1762,
                    2060,
                    2180,
                    2364,
                    383,
                    738,
                    842,
                ],
                'small_quantity_anchor_ranks' => [
                    62,
                    346,
                    562,
                    1450,
                    1762,
                    2060,
                    2180,
                    2364,
                    383,
                    738,
                    842,
                ],
                'bands' => [
                    0.035,
                    0.070,
                    0.110,
                    0.150,
                    0.190,
                    0.230,
                    0.270,
                    0.319,
                    0.390,
                    0.500,
                    0.615,
                    0.702,
                ],
                'small_quantity_bands' => [
                    0.166,
                    0.234,
                    0.236,
                    0.244,
                    0.319,
                    0.615,
                    0.702,
                    0.035,
                    0.070,
                    0.110,
                    0.150,
                    0.190,
                    0.230,
                    0.270,
                    0.390,
                    0.500,
                    0.850,
                ],
            ],

            'elite_selection_audit' => [
                'enabled' => true,
                'top_raw_limit' => 10,
                'log_channel' => 'single',
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
        'faixa_13' => 0,
        'faixa_12' => 0,
        'faixa_11' => 0,
        'raw_14_preserved_bonus' => 50000,
        'raw_13_preserved_bonus' => 0,
        'loss_penalty' => 750,
    ],

];
