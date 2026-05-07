<?php

return [

    'enabled' => true,
    'shadow_mode' => true,
    'use_promoted_only' => true,
    'allow_unvalidated_effects' => false,

    'queue' => 'learning',

    'learning_rate' => (float) env('LOTTUS_MAIN_LEARNING_RATE', 0.08),
    'decay_factor' => (float) env('LOTTUS_MAIN_LEARNING_DECAY_FACTOR', 0.92),
    'confidence_threshold' => (float) env('LOTTUS_MAIN_LEARNING_CONFIDENCE_THRESHOLD', 0.62),
    'max_delta_per_cycle' => (float) env('LOTTUS_MAIN_LEARNING_MAX_DELTA_PER_CYCLE', 0.12),
    'min_sample_size' => (int) env('LOTTUS_MAIN_LEARNING_MIN_SAMPLE_SIZE', 30),
    'min_validation_runs' => (int) env('LOTTUS_MAIN_LEARNING_MIN_VALIDATION_RUNS', 3),
    'min_win_rate' => (float) env('LOTTUS_MAIN_LEARNING_MIN_WIN_RATE', 0.55),
    'min_elite_delta' => (int) env('LOTTUS_MAIN_LEARNING_MIN_ELITE_DELTA', 1),

    'use_strategy_weights' => true,
    'use_number_bias' => true,
    'use_pair_bias' => true,
    'use_structure_bias' => true,
    'use_aggressiveness_control' => true,
    'use_near15_learning' => true,

    'portfolio_calibration' => [
        'enabled' => true,
        'rank_cluster_window' => 420,
        'max_absolute_targets' => 8,
    ],

    'validation_quantities' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
    'validation_lookback' => (int) env('LOTTUS_MAIN_LEARNING_VALIDATION_LOOKBACK', 12),
    'walk_forward_min_history' => 30,
    'evaluate_combined_variant' => (bool) env('LOTTUS_MAIN_LEARNING_EVALUATE_COMBINED', false),

    'candidate_generation' => [
        'adaptive_trend_candidates' => 900,
        'combinational_envelope_candidates' => 0,
        'near15_mutation_candidates' => 500,
        'elite_family_candidates' => 0,
        'single_swap_sweep_candidates' => 36000,
        'double_swap_sweep_candidates' => 0,
        'pair_lattice_candidates' => 700,
        'max_family_seed_candidates' => 1400,
        'max_mutations_per_seed' => 18,
    ],

    'promotion' => [
        'max_loss14' => 0,
        'max_loss15' => 0,
        'minimum_confidence' => 0.62,
        'require_short_package_signal' => true,
    ],

    'overfitting_guards' => [
        'max_number_bias' => 0.18,
        'max_pair_bias' => 0.16,
        'max_structure_delta' => 0.12,
        'max_aggressiveness_delta' => 0.10,
        'shrinkage_floor' => 0.35,
    ],

];
