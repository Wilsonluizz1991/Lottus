<?php

namespace App\Services\Lottus\Generation;

class EliteSurvivalService
{
    public function extract(
        array $rankedGames,
        array $tuning,
        int $quantidade
    ): array {
        if (empty($rankedGames)) {
            return [];
        }

        $survivalTuning = $tuning['elite_survival'] ?? [];
        $selectionLimit = min(
            $quantidade,
            max(0, (int) ($survivalTuning['limit'] ?? 3))
        );

        if ($selectionLimit === 0) {
            return [];
        }

        $pool = array_values($rankedGames);

        $scanLimit = min(
            max($quantidade * 8, (int) ($survivalTuning['minimum_candidates'] ?? 30)),
            count($pool)
        );

        $pool = array_slice($pool, 0, $scanLimit);

        $selected = [];
        $seen = [];
        $minEliteScore = (float) ($survivalTuning['min_score'] ?? 1.0);

        foreach ($pool as $candidate) {
            $key = $this->candidateKey($candidate);

            if (isset($seen[$key])) {
                continue;
            }

            $eliteValue = $this->eliteValue($candidate);

            if ($eliteValue < $minEliteScore) {
                continue;
            }

            $candidate['elite_survival_score'] = round($eliteValue, 6);

            $selected[] = $candidate;
            $seen[$key] = true;
        }

        usort($selected, function ($a, $b) {
            $valueA = (float) ($a['elite_survival_score'] ?? 0);
            $valueB = (float) ($b['elite_survival_score'] ?? 0);

            if ($valueA === $valueB) {
                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            }

            return $valueB <=> $valueA;
        });

        return array_slice($selected, 0, $selectionLimit);
    }

    protected function eliteValue(array $candidate): float
    {
        $score = (float) ($candidate['score'] ?? 0.0);
        $extremeScore = (float) ($candidate['extreme_score'] ?? 0.0);
        $statScore = (float) ($candidate['stat_score'] ?? 0.0);
        $structureScore = (float) ($candidate['structure_score'] ?? 0.0);

        $repeatCount = (int) ($candidate['repetidas_ultimo_concurso'] ?? 0);
        $cycleHits = (int) ($candidate['cycle_hits'] ?? 0);
        $sum = (int) ($candidate['soma'] ?? 0);
        $oddCount = (int) ($candidate['impares'] ?? 0);

        $sequence = (int) ($candidate['analise']['sequencia_maxima'] ?? 0);
        $clusterStrength = (float) ($candidate['analise']['cluster_strength'] ?? 0.0);

        $frequencyQuality = (float) ($candidate['analise']['frequency_quality'] ?? 0.0);
        $delayQuality = (float) ($candidate['analise']['delay_quality'] ?? 0.0);
        $correlationQuality = (float) ($candidate['analise']['correlation_quality'] ?? 0.0);
        $repeatQuality = (float) ($candidate['analise']['repeat_quality'] ?? 0.0);
        $cycleQuality = (float) ($candidate['analise']['cycle_quality'] ?? 0.0);
        $clusterQuality = (float) ($candidate['analise']['cluster_quality'] ?? 0.0);
        $quadrantQuality = (float) ($candidate['analise']['quadrant_quality'] ?? 0.0);

        $elite = 0.0;

        $elite += $score * 0.55;
        $elite += $extremeScore * 0.28;
        $elite += $statScore * 0.12;
        $elite += $structureScore * 0.05;

        if ($repeatCount >= 8 && $repeatCount <= 11) {
            $elite += 120;
        } elseif ($repeatCount === 7 || $repeatCount === 12) {
            $elite += 60;
        }

        if ($cycleHits >= 3) {
            $elite += 80;
        } elseif ($cycleHits === 2) {
            $elite += 35;
        }

        if ($sum >= 170 && $sum <= 215) {
            $elite += 60;
        }

        if ($oddCount >= 6 && $oddCount <= 9) {
            $elite += 45;
        }

        if ($sequence >= 3 && $sequence <= 6) {
            $elite += 55;
        }

        if ($clusterStrength >= 8) {
            $elite += 75;
        }

        if (
            $frequencyQuality >= 0.70 &&
            $correlationQuality >= 0.70 &&
            $repeatQuality >= 0.80
        ) {
            $elite += 180;
        }

        if (
            $clusterQuality >= 0.70 &&
            $quadrantQuality >= 0.70
        ) {
            $elite += 95;
        }

        if (
            $delayQuality >= 0.65 &&
            $cycleQuality >= 0.65
        ) {
            $elite += 65;
        }

        return $elite;
    }

    protected function candidateKey(array $candidate): string
    {
        $dezenas = $candidate['dezenas'] ?? [];

        $dezenas = array_values(array_unique(array_map('intval', $dezenas)));

        sort($dezenas);

        return implode('-', $dezenas);
    }
}
