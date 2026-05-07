<?php

namespace App\Services\Lottus\MainLearning;

class LottusMainBehaviorAnalysisService
{
    public function metricsFromBacktest(array $summary): array
    {
        $diagnostics = $summary['diagnostico'] ?? [];
        $rawEliteRanks = [];
        $selectedDistances = [];
        $rawDistances = [];

        foreach ($diagnostics as $row) {
            if (($row['raw'] ?? 0) >= 14 && ($row['raw_rank'] ?? null) !== null) {
                $rawEliteRanks[] = (int) $row['raw_rank'];
            }

            $selectedDistances[] = max(0, 15 - (int) ($row['selected'] ?? 0));
            $rawDistances[] = max(0, 15 - (int) ($row['raw'] ?? 0));
        }

        $raw14 = (int) ($summary['raw_melhor_faixas'][14] ?? 0);
        $raw15 = (int) ($summary['raw_melhor_faixas'][15] ?? 0);
        $selected14 = (int) ($summary['faixas'][14] ?? 0);
        $selected15 = (int) ($summary['faixas'][15] ?? 0);
        $near15 = (int) ($summary['near_15_raw_candidates'] ?? 0);
        $loss = (int) ($summary['raw_14_15_loss'] ?? 0);

        return [
            'concursos' => (int) ($summary['concursos_testados'] ?? 0),
            'jogos' => (int) ($summary['jogos_gerados'] ?? 0),
            'raw14' => $raw14,
            'raw15' => $raw15,
            'selected14' => $selected14,
            'selected15' => $selected15,
            'near15' => $near15,
            'raw_14_15_total' => (int) ($summary['raw_14_15_total'] ?? 0),
            'raw_14_15_preservados' => (int) ($summary['raw_14_15_preservados'] ?? 0),
            'loss14_15' => $loss,
            'selected_14_plus_contests' => (int) ($summary['selected_14_plus_contests'] ?? 0),
            'selected_15_contests' => (int) ($summary['selected_15_contests'] ?? 0),
            'avg_raw_elite_rank' => $this->average($rawEliteRanks),
            'avg_selected_distance_to_15' => $this->average($selectedDistances),
            'avg_raw_distance_to_15' => $this->average($rawDistances),
            'elite_score' => $this->eliteScore($raw14, $raw15, $selected14, $selected15, $near15, $loss),
        ];
    }

    public function delta(array $baseline, array $learned): array
    {
        $keys = [
            'raw14',
            'raw15',
            'selected14',
            'selected15',
            'near15',
            'raw_14_15_total',
            'raw_14_15_preservados',
            'loss14_15',
            'selected_14_plus_contests',
            'selected_15_contests',
            'avg_raw_elite_rank',
            'avg_selected_distance_to_15',
            'avg_raw_distance_to_15',
            'elite_score',
        ];

        $delta = [];

        foreach ($keys as $key) {
            $delta[$key] = round((float) ($learned[$key] ?? 0) - (float) ($baseline[$key] ?? 0), 6);
        }

        return $delta;
    }

    public function isImprovement(array $delta): bool
    {
        return ((float) ($delta['raw15'] ?? 0) > 0)
            || ((float) ($delta['selected15'] ?? 0) > 0)
            || ((float) ($delta['raw14'] ?? 0) > 0)
            || ((float) ($delta['selected14'] ?? 0) > 0)
            || ((float) ($delta['near15'] ?? 0) > 0)
            || ((float) ($delta['selected_14_plus_contests'] ?? 0) > 0)
            || ((float) ($delta['avg_raw_elite_rank'] ?? 0) < 0)
            || ((float) ($delta['avg_selected_distance_to_15'] ?? 0) < 0)
            || ((float) ($delta['elite_score'] ?? 0) > 0);
    }

    protected function eliteScore(int $raw14, int $raw15, int $selected14, int $selected15, int $near15, int $loss): float
    {
        return ($selected15 * 5000.0)
            + ($raw15 * 2600.0)
            + ($selected14 * 900.0)
            + ($raw14 * 420.0)
            + ($near15 * 160.0)
            - ($loss * 1200.0);
    }

    protected function average(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }

        return round(array_sum($values) / count($values), 4);
    }
}
