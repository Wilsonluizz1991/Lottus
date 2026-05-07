<?php

namespace App\Services\Lottus\MainLearning;

class AggressivenessCalibrationEngine
{
    public function calibrate(array $baselineMetrics, array $trendPayload, ?array $previousPayload = null): array
    {
        $previous = $previousPayload['aggressiveness'] ?? [];
        $maxDelta = (float) config('lottus_main_learning.overfitting_guards.max_aggressiveness_delta', 0.10);
        $raw14 = (int) ($baselineMetrics['raw14'] ?? 0);
        $raw15 = (int) ($baselineMetrics['raw15'] ?? 0);
        $near15 = (int) ($baselineMetrics['near15'] ?? 0);
        $loss = (int) ($baselineMetrics['loss14_15'] ?? 0);
        $concursos = max(1, (int) ($baselineMetrics['concursos'] ?? 1));
        $drift = (float) ($trendPayload['trend_metrics']['drift']['number_drift_avg'] ?? 0.0);

        $raw14Rate = ($raw14 + $raw15) / $concursos;
        $near15Rate = $near15 / $concursos;

        $explorationDelta = 0.0;
        $concentrationDelta = 0.0;
        $mutationDelta = 0.0;

        if ($raw14Rate < 0.05) {
            $explorationDelta += 0.08;
            $mutationDelta += 0.07;
        }

        if ($near15Rate < 0.08) {
            $explorationDelta += 0.04;
            $mutationDelta += 0.05;
        }

        if ($near15Rate >= 0.16 && $raw14Rate < 0.08) {
            $concentrationDelta += 0.05;
            $mutationDelta += 0.04;
        }

        if ($loss > 0) {
            $concentrationDelta += 0.09;
            $explorationDelta -= 0.03;
        }

        if ($drift >= 0.08) {
            $explorationDelta += 0.04;
        }

        $exploration = $this->bounded((float) ($previous['exploration'] ?? 1.0) + $this->bounded($explorationDelta, -$maxDelta, $maxDelta), 0.82, 1.28);
        $concentration = $this->bounded((float) ($previous['elite_concentration'] ?? 1.0) + $this->bounded($concentrationDelta, -$maxDelta, $maxDelta), 0.88, 1.30);
        $mutation = $this->bounded((float) ($previous['mutation_depth'] ?? 1.0) + $this->bounded($mutationDelta, -$maxDelta, $maxDelta), 0.82, 1.32);

        return [
            'exploration' => round($exploration, 6),
            'elite_concentration' => round($concentration, 6),
            'mutation_depth' => round($mutation, 6),
            'candidate_multiplier' => round($this->bounded(($exploration + $mutation) / 2, 0.90, 1.28), 6),
            'reason' => [
                'raw14_rate' => round($raw14Rate, 6),
                'near15_rate' => round($near15Rate, 6),
                'loss14_15' => $loss,
                'drift' => round($drift, 6),
            ],
        ];
    }

    protected function bounded(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
