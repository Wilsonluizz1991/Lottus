<?php

namespace App\Services\Lottus\MainLearning;

use Illuminate\Support\Collection;

class LottusMainTrendDetectionService
{
    public function detect(Collection|array $historico, ?array $previousPayload = null): array
    {
        $draws = $this->historicalDraws($historico);

        if (count($draws) < (int) config('lottus_main_learning.min_sample_size', 30)) {
            return $this->emptyPayload('historico_insuficiente');
        }

        $short = array_slice($draws, -20);
        $medium = array_slice($draws, -80);
        $long = array_slice($draws, -180);

        $shortFreq = $this->frequencyVector($short);
        $mediumFreq = $this->frequencyVector($medium);
        $longFreq = $this->frequencyVector($long);
        $numberBias = $this->numberBias($shortFreq, $mediumFreq, $longFreq, $previousPayload['number_bias'] ?? []);
        $pairBias = $this->pairBias($short, $medium, $long, $previousPayload['pair_bias'] ?? []);
        $structure = $this->structureBias($short, $medium, $long, $previousPayload['structure_bias'] ?? []);
        $strategyWeights = $this->strategyWeights($structure, $numberBias, $previousPayload['strategy_weights'] ?? []);

        return [
            'version' => 1,
            'generated_at' => now()->toISOString(),
            'sample_size' => count($draws),
            'number_bias' => $numberBias,
            'pair_bias' => $pairBias,
            'structure_bias' => $structure,
            'strategy_weights' => $strategyWeights,
            'score_adjustments' => $this->scoreAdjustments($structure),
            'raw_elite_protection' => [
                'enabled' => true,
                'min_elite_rank_boost' => 0.08,
                'near15_boost' => 0.10,
                'loss_guard' => true,
            ],
            'portfolio_rules' => [
                'short_packages_elite_first' => true,
                'diversity_as_tiebreaker' => true,
                'max_quantity_elite_first' => 10,
                'dynamic_elite_portfolio' => [
                    'conversion_sweep' => [
                        'enabled' => true,
                        'shortlist_quantity' => 10,
                        'bands' => [0.02, 0.16, 0.25, 0.40, 0.49, 0.82, 0.96],
                        'family_radii' => [16, 32, 96, 64, 128, 192],
                        'family_cluster_radius_min' => 80,
                        'family_cluster_window' => 12,
                        'family_cluster_size' => 3,
                    ],
                ],
            ],
            'trend_metrics' => [
                'short' => $this->windowMetrics($short),
                'medium' => $this->windowMetrics($medium),
                'long' => $this->windowMetrics($long),
                'drift' => $this->driftMetrics($shortFreq, $mediumFreq, $longFreq),
            ],
        ];
    }

    protected function emptyPayload(string $reason): array
    {
        return [
            'version' => 1,
            'generated_at' => now()->toISOString(),
            'sample_size' => 0,
            'number_bias' => [],
            'pair_bias' => [],
            'structure_bias' => [],
            'strategy_weights' => [],
            'score_adjustments' => [],
            'raw_elite_protection' => ['enabled' => true],
            'portfolio_rules' => [
                'short_packages_elite_first' => true,
                'dynamic_elite_portfolio' => [],
            ],
            'trend_metrics' => ['reason' => $reason],
        ];
    }

    protected function numberBias(array $short, array $medium, array $long, array $previous): array
    {
        $maxDelta = (float) config('lottus_main_learning.overfitting_guards.max_number_bias', 0.18);
        $learningRate = (float) config('lottus_main_learning.learning_rate', 0.08);
        $bias = [];

        foreach (range(1, 25) as $number) {
            $shortValue = (float) ($short[$number] ?? 0.0);
            $mediumValue = (float) ($medium[$number] ?? 0.0);
            $longValue = (float) ($long[$number] ?? 0.0);
            $momentum = ($shortValue - $longValue) * 0.65 + ($mediumValue - $longValue) * 0.25;
            $stability = min($shortValue, $mediumValue) - $longValue;
            $rawDelta = ($momentum + ($stability * 0.45)) * $learningRate;
            $old = (float) ($previous[$number] ?? $previous[(string) $number] ?? 0.0);

            $bias[$number] = round($this->bounded(($old * 0.55) + $rawDelta, -$maxDelta, $maxDelta), 6);
        }

        arsort($bias);

        return $bias;
    }

    protected function pairBias(array $short, array $medium, array $long, array $previous): array
    {
        $maxDelta = (float) config('lottus_main_learning.overfitting_guards.max_pair_bias', 0.16);
        $learningRate = (float) config('lottus_main_learning.learning_rate', 0.08);
        $shortPairs = $this->pairVector($short);
        $mediumPairs = $this->pairVector($medium);
        $longPairs = $this->pairVector($long);
        $pairs = [];

        foreach (range(1, 25) as $a) {
            foreach (range($a + 1, 25) as $b) {
                $key = $a . '-' . $b;
                $rawDelta = (
                    ((float) ($shortPairs[$key] ?? 0.0) - (float) ($longPairs[$key] ?? 0.0)) * 0.70
                    + ((float) ($mediumPairs[$key] ?? 0.0) - (float) ($longPairs[$key] ?? 0.0)) * 0.30
                ) * $learningRate;
                $old = (float) ($previous[$key] ?? 0.0);
                $value = $this->bounded(($old * 0.50) + $rawDelta, -$maxDelta, $maxDelta);

                if (abs($value) >= 0.003) {
                    $pairs[$key] = round($value, 6);
                }
            }
        }

        arsort($pairs);

        return array_slice($pairs, 0, 120, true);
    }

    protected function structureBias(array $short, array $medium, array $long, array $previous): array
    {
        $shortMetrics = $this->windowMetrics($short);
        $mediumMetrics = $this->windowMetrics($medium);
        $longMetrics = $this->windowMetrics($long);
        $maxDelta = (float) config('lottus_main_learning.overfitting_guards.max_structure_delta', 0.12);

        $targets = [
            'repeat_target' => $this->smoothedTarget($shortMetrics['repeat_avg'], $mediumMetrics['repeat_avg'], $longMetrics['repeat_avg']),
            'sum_target' => $this->smoothedTarget($shortMetrics['sum_avg'], $mediumMetrics['sum_avg'], $longMetrics['sum_avg']),
            'odd_target' => $this->smoothedTarget($shortMetrics['odd_avg'], $mediumMetrics['odd_avg'], $longMetrics['odd_avg']),
            'frame_target' => $this->smoothedTarget($shortMetrics['frame_avg'], $mediumMetrics['frame_avg'], $longMetrics['frame_avg']),
            'sequence_target' => $this->smoothedTarget($shortMetrics['sequence_avg'], $mediumMetrics['sequence_avg'], $longMetrics['sequence_avg']),
        ];

        $drift = [
            'repeat_delta' => $this->bounded(($shortMetrics['repeat_avg'] - $longMetrics['repeat_avg']) / 15, -$maxDelta, $maxDelta),
            'sum_delta' => $this->bounded(($shortMetrics['sum_avg'] - $longMetrics['sum_avg']) / 220, -$maxDelta, $maxDelta),
            'odd_delta' => $this->bounded(($shortMetrics['odd_avg'] - $longMetrics['odd_avg']) / 15, -$maxDelta, $maxDelta),
            'frame_delta' => $this->bounded(($shortMetrics['frame_avg'] - $longMetrics['frame_avg']) / 15, -$maxDelta, $maxDelta),
            'sequence_delta' => $this->bounded(($shortMetrics['sequence_avg'] - $longMetrics['sequence_avg']) / 10, -$maxDelta, $maxDelta),
        ];

        foreach ($drift as $key => $value) {
            $old = (float) ($previous[$key] ?? 0.0);
            $drift[$key] = round($this->bounded(($old * 0.45) + ($value * 0.55), -$maxDelta, $maxDelta), 6);
        }

        return array_merge($targets, $drift);
    }

    protected function strategyWeights(array $structure, array $numberBias, array $previous): array
    {
        $volatility = array_sum(array_map('abs', array_slice($numberBias, 0, 12, true))) / 12;
        $repeatDelta = (float) ($structure['repeat_delta'] ?? 0.0);
        $sumDelta = abs((float) ($structure['sum_delta'] ?? 0.0));
        $maxDelta = (float) config('lottus_main_learning.max_delta_per_cycle', 0.12);

        $weights = [
            'baseline_explosive' => 1.00,
            'elite_high_ceiling' => 1.08 + ($volatility * 1.4),
            'correlation_cluster' => 1.06 + ($volatility * 1.0),
            'strategic_repeat' => 1.02 + max(0.0, $repeatDelta * 2.4),
            'controlled_delay' => 1.02 + max(0.0, -$repeatDelta * 1.7),
            'explosive_hybrid' => 1.10 + ($sumDelta * 1.5),
            'anti_mean_high_ceiling' => 1.06 + ($volatility * 1.2),
            'historical_replay' => 1.04,
            'near15_mutation' => 1.14 + ($volatility * 1.6),
            'elite_family_expansion' => 1.16 + ($volatility * 1.8),
            'single_swap_sweep' => 1.18 + ($volatility * 1.7),
            'double_swap_sweep' => 1.20 + ($volatility * 1.8),
            'trend_adaptive_core' => 1.12 + ($volatility * 1.4),
        ];

        foreach ($weights as $key => $value) {
            $old = (float) ($previous[$key] ?? 1.0);
            $weights[$key] = round($this->bounded(($old * 0.50) + ($value * 0.50), 1.0 - $maxDelta, 1.0 + $maxDelta), 6);
        }

        return $weights;
    }

    protected function scoreAdjustments(array $structure): array
    {
        return [
            'elite_potential_multiplier' => round(1.0 + abs((float) ($structure['repeat_delta'] ?? 0.0)) + abs((float) ($structure['sum_delta'] ?? 0.0)), 6),
            'near15_multiplier' => 1.08,
            'anti_average_multiplier' => 1.12,
        ];
    }

    protected function driftMetrics(array $short, array $medium, array $long): array
    {
        $values = [];

        foreach (range(1, 25) as $number) {
            $values[] = abs(((float) ($short[$number] ?? 0.0)) - ((float) ($long[$number] ?? 0.0)));
        }

        return [
            'number_drift_avg' => round(array_sum($values) / max(1, count($values)), 6),
            'number_drift_max' => round(max($values), 6),
        ];
    }

    protected function smoothedTarget(float $short, float $medium, float $long): float
    {
        return round(($short * 0.52) + ($medium * 0.31) + ($long * 0.17), 4);
    }

    protected function frequencyVector(array $draws): array
    {
        $counts = array_fill_keys(range(1, 25), 0.0);

        foreach ($draws as $draw) {
            foreach ($draw as $number) {
                $counts[(int) $number] += 1.0;
            }
        }

        $total = max(1, count($draws));

        foreach ($counts as $number => $count) {
            $counts[$number] = $count / $total;
        }

        return $counts;
    }

    protected function pairVector(array $draws): array
    {
        $counts = [];

        foreach ($draws as $draw) {
            $draw = array_values($draw);

            for ($i = 0; $i < count($draw) - 1; $i++) {
                for ($j = $i + 1; $j < count($draw); $j++) {
                    $a = min($draw[$i], $draw[$j]);
                    $b = max($draw[$i], $draw[$j]);
                    $key = $a . '-' . $b;
                    $counts[$key] = ($counts[$key] ?? 0.0) + 1.0;
                }
            }
        }

        $total = max(1, count($draws));

        foreach ($counts as $key => $count) {
            $counts[$key] = $count / $total;
        }

        return $counts;
    }

    protected function windowMetrics(array $draws): array
    {
        if (empty($draws)) {
            return [
                'repeat_avg' => 0.0,
                'sum_avg' => 0.0,
                'odd_avg' => 0.0,
                'frame_avg' => 0.0,
                'sequence_avg' => 0.0,
            ];
        }

        $repeat = [];
        $sum = [];
        $odd = [];
        $frame = [];
        $sequence = [];

        foreach ($draws as $index => $draw) {
            $sum[] = array_sum($draw);
            $odd[] = count(array_filter($draw, fn (int $number): bool => $number % 2 !== 0));
            $frame[] = $this->frameCount($draw);
            $sequence[] = $this->longestSequence($draw);

            if ($index > 0) {
                $repeat[] = count(array_intersect($draw, $draws[$index - 1]));
            }
        }

        return [
            'repeat_avg' => round(array_sum($repeat) / max(1, count($repeat)), 4),
            'sum_avg' => round(array_sum($sum) / max(1, count($sum)), 4),
            'odd_avg' => round(array_sum($odd) / max(1, count($odd)), 4),
            'frame_avg' => round(array_sum($frame) / max(1, count($frame)), 4),
            'sequence_avg' => round(array_sum($sequence) / max(1, count($sequence)), 4),
        ];
    }

    protected function historicalDraws(Collection|array $historico): array
    {
        $items = $historico instanceof Collection ? $historico->values()->all() : array_values($historico);
        $draws = [];

        foreach ($items as $item) {
            $numbers = [];

            if (is_array($item)) {
                $numbers = $item['dezenas'] ?? $item['numbers'] ?? [];

                if (empty($numbers)) {
                    for ($i = 1; $i <= 15; $i++) {
                        if (isset($item['bola' . $i])) {
                            $numbers[] = (int) $item['bola' . $i];
                        }
                    }
                }
            } elseif (is_object($item)) {
                for ($i = 1; $i <= 15; $i++) {
                    $key = 'bola' . $i;

                    if (isset($item->{$key})) {
                        $numbers[] = (int) $item->{$key};
                    }
                }
            }

            $numbers = array_values(array_unique(array_map('intval', $numbers)));
            sort($numbers);

            if (count($numbers) === 15) {
                $draws[] = $numbers;
            }
        }

        return $draws;
    }

    protected function frameCount(array $game): int
    {
        $frame = [1, 2, 3, 4, 5, 6, 10, 11, 15, 16, 20, 21, 22, 23, 24, 25];

        return count(array_intersect($game, $frame));
    }

    protected function longestSequence(array $game): int
    {
        $game = array_values($game);
        sort($game);
        $longest = 1;
        $current = 1;

        for ($i = 1; $i < count($game); $i++) {
            if ($game[$i] === $game[$i - 1] + 1) {
                $current++;
                $longest = max($longest, $current);
            } else {
                $current = 1;
            }
        }

        return $longest;
    }

    protected function bounded(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
