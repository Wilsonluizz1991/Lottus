<?php

namespace App\Services\Lottus\Fechamento;

use App\Models\LotofacilConcurso;

class FechamentoScoreService
{
    public function score(
        array $combinations,
        array $frequencyContext,
        array $delayContext,
        array $correlationContext,
        array $structureContext,
        array $cycleContext,
        LotofacilConcurso $concursoBase
    ): array {
        $lastDraw = $this->extractNumbers($concursoBase);
        $frequencyScores = $this->normalizeScores($frequencyContext['scores'] ?? []);
        $delayScores = $this->normalizeScores($delayContext['scores'] ?? []);
        $cycleScores = $this->normalizeScores($cycleContext['scores'] ?? []);
        $pairScores = $correlationContext['pair_scores'] ?? [];
        $faltantes = array_values(array_map('intval', $cycleContext['faltantes'] ?? []));

        $scored = [];

        foreach ($combinations as $combination) {
            $game = array_values(array_unique(array_map('intval', $combination)));
            sort($game);

            if (count($game) !== 15) {
                continue;
            }

            $frequencyQuality = $this->averageMetric($game, $frequencyScores);
            $delayQuality = $this->averageMetric($game, $delayScores);
            $cycleQuality = $this->averageMetric($game, $cycleScores);
            $correlationQuality = $this->pairQuality($game, $pairScores);

            $repeatCount = count(array_intersect($game, $lastDraw));
            $cycleHits = count(array_intersect($game, $faltantes));
            $sum = array_sum($game);
            $oddCount = count(array_filter($game, fn ($number) => $number % 2 !== 0));
            $evenCount = 15 - $oddCount;
            $longestSequence = $this->longestSequence($game);
            $lineDistribution = $this->lineDistribution($game);
            $quadrantDistribution = $this->quadrantDistribution($game);
            $frameCount = $this->frameCount($game);
            $middleCount = 15 - $frameCount;
            $clusterStrength = $this->clusterStrength($game);

            $structureQuality = $this->structureQuality(
                sum: $sum,
                oddCount: $oddCount,
                evenCount: $evenCount,
                repeatCount: $repeatCount,
                cycleHits: $cycleHits,
                longestSequence: $longestSequence,
                lineDistribution: $lineDistribution,
                quadrantDistribution: $quadrantDistribution,
                frameCount: $frameCount,
                middleCount: $middleCount,
                clusterStrength: $clusterStrength
            );

            $survivalQuality = $this->survivalQuality(
                frequencyQuality: $frequencyQuality,
                delayQuality: $delayQuality,
                cycleQuality: $cycleQuality,
                correlationQuality: $correlationQuality,
                repeatCount: $repeatCount,
                cycleHits: $cycleHits,
                sum: $sum,
                oddCount: $oddCount,
                longestSequence: $longestSequence,
                lineDistribution: $lineDistribution,
                quadrantDistribution: $quadrantDistribution,
                frameCount: $frameCount,
                clusterStrength: $clusterStrength
            );

            $aestheticPenalty = $this->aestheticPenalty(
                structureQuality: $structureQuality,
                sum: $sum,
                oddCount: $oddCount,
                repeatCount: $repeatCount,
                longestSequence: $longestSequence,
                clusterStrength: $clusterStrength,
                lineDistribution: $lineDistribution,
                quadrantDistribution: $quadrantDistribution
            );

            $baseScore =
                ($frequencyQuality * 9.6) +
                ($delayQuality * 8.8) +
                ($cycleQuality * 9.4) +
                ($correlationQuality * 9.8) +
                ($structureQuality * 2.2) +
                ($survivalQuality * 13.5);

            $eliteBonus = $this->eliteBonus(
                frequencyQuality: $frequencyQuality,
                delayQuality: $delayQuality,
                cycleQuality: $cycleQuality,
                correlationQuality: $correlationQuality,
                structureQuality: $structureQuality,
                survivalQuality: $survivalQuality,
                repeatCount: $repeatCount,
                cycleHits: $cycleHits,
                sum: $sum,
                oddCount: $oddCount,
                longestSequence: $longestSequence,
                clusterStrength: $clusterStrength
            );

            $score = $baseScore + $eliteBonus - $aestheticPenalty;

            $scored[] = [
                'dezenas' => $game,
                'score' => round($score, 6),
                'base_score' => round($baseScore, 6),
                'elite_bonus' => round($eliteBonus, 6),
                'aesthetic_penalty' => round($aestheticPenalty, 6),
                'frequency_quality' => round($frequencyQuality, 6),
                'delay_quality' => round($delayQuality, 6),
                'cycle_quality' => round($cycleQuality, 6),
                'correlation_quality' => round($correlationQuality, 6),
                'structure_quality' => round($structureQuality, 6),
                'survival_quality' => round($survivalQuality, 6),
                'pares' => $evenCount,
                'impares' => $oddCount,
                'soma' => $sum,
                'repetidas_ultimo_concurso' => $repeatCount,
                'cycle_hits' => $cycleHits,
                'sequencia_maxima' => $longestSequence,
                'linhas' => $lineDistribution,
                'quadrantes' => $quadrantDistribution,
                'moldura' => $frameCount,
                'miolo' => $middleCount,
                'cluster_strength' => round($clusterStrength, 6),
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $scored;
    }

    protected function normalizeScores(array $scores): array
    {
        $normalized = [];
        $values = [];

        foreach (range(1, 25) as $number) {
            $values[$number] = (float) ($scores[$number] ?? 0.0);
        }

        $min = min($values);
        $max = max($values);

        foreach ($values as $number => $value) {
            if ($max <= $min) {
                $normalized[$number] = 0.5;
                continue;
            }

            $normalized[$number] = ($value - $min) / ($max - $min);
        }

        return $normalized;
    }

    protected function averageMetric(array $game, array $scores): float
    {
        if (empty($game)) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($game as $number) {
            $total += (float) ($scores[$number] ?? 0.0);
        }

        return $total / count($game);
    }

    protected function pairQuality(array $game, array $pairScores): float
    {
        $total = 0.0;
        $count = 0;

        for ($i = 0; $i < count($game); $i++) {
            for ($j = $i + 1; $j < count($game); $j++) {
                $a = $game[$i];
                $b = $game[$j];

                $total += (float) ($pairScores[$a][$b] ?? $pairScores[$b][$a] ?? 0.0);
                $count++;
            }
        }

        if ($count === 0) {
            return 0.0;
        }

        return 1.0 / (1.0 + exp(-($total / $count)));
    }

    protected function structureQuality(
        int $sum,
        int $oddCount,
        int $evenCount,
        int $repeatCount,
        int $cycleHits,
        int $longestSequence,
        array $lineDistribution,
        array $quadrantDistribution,
        int $frameCount,
        int $middleCount,
        float $clusterStrength
    ): float {
        $quality = 0.0;

        $soft = config('lottus_fechamento.soft_structure', []);

        $sumMin = (int) ($soft['sum_min'] ?? 165);
        $sumMax = (int) ($soft['sum_max'] ?? 220);
        $oddMin = (int) ($soft['odd_min'] ?? 6);
        $oddMax = (int) ($soft['odd_max'] ?? 9);
        $repeatMin = (int) ($soft['repeat_min'] ?? 7);
        $repeatMax = (int) ($soft['repeat_max'] ?? 12);
        $maxSequence = (int) ($soft['max_sequence'] ?? 7);

        if ($sum >= $sumMin && $sum <= $sumMax) {
            $quality += 0.12;
        } elseif ($sum >= ($sumMin - 14) && $sum <= ($sumMax + 14)) {
            $quality += 0.08;
        }

        if ($oddCount >= $oddMin && $oddCount <= $oddMax) {
            $quality += 0.11;
        } elseif ($oddCount >= 5 && $oddCount <= 10) {
            $quality += 0.07;
        }

        if ($repeatCount >= $repeatMin && $repeatCount <= $repeatMax) {
            $quality += 0.12;
        } elseif ($repeatCount >= 6 && $repeatCount <= 13) {
            $quality += 0.08;
        }

        if ($cycleHits >= 2) {
            $quality += 0.10;
        }

        if ($longestSequence >= 3 && $longestSequence <= $maxSequence) {
            $quality += 0.09;
        } elseif ($longestSequence <= 8) {
            $quality += 0.06;
        }

        $maxLine = max($lineDistribution);
        $minLine = min($lineDistribution);

        if ($maxLine <= 4 && $minLine >= 2) {
            $quality += 0.08;
        } elseif ($maxLine <= 5 && $minLine >= 1) {
            $quality += 0.06;
        }

        $maxQuadrant = max($quadrantDistribution);
        $minQuadrant = min($quadrantDistribution);

        if ($maxQuadrant <= 5 && $minQuadrant >= 2) {
            $quality += 0.07;
        } elseif ($maxQuadrant <= 6 && $minQuadrant >= 1) {
            $quality += 0.05;
        }

        if ($frameCount >= 8 && $frameCount <= 12) {
            $quality += 0.06;
        } elseif ($frameCount >= 7 && $frameCount <= 13) {
            $quality += 0.04;
        }

        if ($clusterStrength >= 8) {
            $quality += 0.03;
        }

        return min(1.0, $quality);
    }

    protected function survivalQuality(
        float $frequencyQuality,
        float $delayQuality,
        float $cycleQuality,
        float $correlationQuality,
        int $repeatCount,
        int $cycleHits,
        int $sum,
        int $oddCount,
        int $longestSequence,
        array $lineDistribution,
        array $quadrantDistribution,
        int $frameCount,
        float $clusterStrength
    ): float {
        $quality = 0.0;

        if ($frequencyQuality >= 0.52) {
            $quality += 0.13;
        }

        if ($delayQuality >= 0.48) {
            $quality += 0.12;
        }

        if ($cycleQuality >= 0.50) {
            $quality += 0.13;
        }

        if ($correlationQuality >= 0.66) {
            $quality += 0.11;
        }

        if ($repeatCount >= 7 && $repeatCount <= 12) {
            $quality += 0.13;
        } elseif ($repeatCount >= 6 && $repeatCount <= 13) {
            $quality += 0.08;
        }

        if ($cycleHits >= 2 && $cycleHits <= 6) {
            $quality += 0.11;
        } elseif ($cycleHits >= 1) {
            $quality += 0.06;
        }

        if ($sum >= 160 && $sum <= 225) {
            $quality += 0.08;
        }

        if ($oddCount >= 5 && $oddCount <= 10) {
            $quality += 0.08;
        }

        if ($longestSequence >= 2 && $longestSequence <= 8) {
            $quality += 0.06;
        }

        if (max($lineDistribution) <= 5 && min($lineDistribution) >= 1) {
            $quality += 0.06;
        }

        if (max($quadrantDistribution) <= 6 && min($quadrantDistribution) >= 1) {
            $quality += 0.05;
        }

        if ($frameCount >= 7 && $frameCount <= 13) {
            $quality += 0.05;
        }

        if ($clusterStrength >= 7 && $clusterStrength <= 15) {
            $quality += 0.04;
        }

        return min(1.0, $quality);
    }

    protected function aestheticPenalty(
        float $structureQuality,
        int $sum,
        int $oddCount,
        int $repeatCount,
        int $longestSequence,
        float $clusterStrength,
        array $lineDistribution,
        array $quadrantDistribution
    ): float {
        $penalty = 0.0;

        if ($structureQuality >= 0.92) {
            $penalty += 1.4;
        }

        if ($sum >= 180 && $sum <= 205 && $oddCount >= 7 && $oddCount <= 8) {
            $penalty += 0.8;
        }

        if ($repeatCount >= 8 && $repeatCount <= 10 && $longestSequence >= 3 && $longestSequence <= 5) {
            $penalty += 0.7;
        }

        if ($clusterStrength >= 13) {
            $penalty += 0.6;
        }

        if (max($lineDistribution) <= 4 && min($lineDistribution) >= 2) {
            $penalty += 0.4;
        }

        if (max($quadrantDistribution) <= 5 && min($quadrantDistribution) >= 2) {
            $penalty += 0.4;
        }

        return $penalty;
    }

    protected function eliteBonus(
        float $frequencyQuality,
        float $delayQuality,
        float $cycleQuality,
        float $correlationQuality,
        float $structureQuality,
        float $survivalQuality,
        int $repeatCount,
        int $cycleHits,
        int $sum,
        int $oddCount,
        int $longestSequence,
        float $clusterStrength
    ): float {
        $bonus = 0.0;

        if ($survivalQuality >= 0.72) {
            $bonus += 4.2;
        }

        if ($survivalQuality >= 0.80) {
            $bonus += 3.5;
        }

        if ($frequencyQuality >= 0.55 && $cycleQuality >= 0.52) {
            $bonus += 2.8;
        }

        if ($delayQuality >= 0.50 && $cycleQuality >= 0.54) {
            $bonus += 2.6;
        }

        if ($correlationQuality >= 0.68 && $frequencyQuality >= 0.52) {
            $bonus += 2.4;
        }

        if ($repeatCount >= 7 && $repeatCount <= 12) {
            $bonus += 2.8;
        }

        if ($cycleHits >= 2 && $cycleHits <= 6) {
            $bonus += 2.5;
        }

        if ($sum >= 160 && $sum <= 225) {
            $bonus += 1.4;
        }

        if ($oddCount >= 5 && $oddCount <= 10) {
            $bonus += 1.3;
        }

        if ($longestSequence >= 2 && $longestSequence <= 8) {
            $bonus += 1.2;
        }

        if ($clusterStrength >= 7 && $clusterStrength <= 15) {
            $bonus += 1.0;
        }

        if (
            $survivalQuality >= 0.76 &&
            $frequencyQuality >= 0.54 &&
            $cycleQuality >= 0.52 &&
            $correlationQuality >= 0.66
        ) {
            $bonus += 5.5;
        }

        if (
            $structureQuality >= 0.72 &&
            $survivalQuality < 0.62
        ) {
            $bonus -= 2.5;
        }

        return $bonus;
    }

    protected function lineDistribution(array $game): array
    {
        $lines = [0, 0, 0, 0, 0];

        foreach ($game as $number) {
            $index = (int) floor(($number - 1) / 5);
            $lines[$index]++;
        }

        return $lines;
    }

    protected function quadrantDistribution(array $game): array
    {
        $quadrants = [0, 0, 0, 0];

        foreach ($game as $number) {
            if ($number >= 1 && $number <= 7) {
                $quadrants[0]++;
            } elseif ($number >= 8 && $number <= 13) {
                $quadrants[1]++;
            } elseif ($number >= 14 && $number <= 19) {
                $quadrants[2]++;
            } else {
                $quadrants[3]++;
            }
        }

        return $quadrants;
    }

    protected function longestSequence(array $game): int
    {
        if (empty($game)) {
            return 0;
        }

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

    protected function frameCount(array $game): int
    {
        $frameNumbers = [
            1, 2, 3, 4, 5,
            6, 10,
            11, 15,
            16, 20,
            21, 22, 23, 24, 25,
        ];

        return count(array_intersect($game, $frameNumbers));
    }

    protected function clusterStrength(array $game): float
    {
        $clusters = 0;
        $run = 1;

        for ($i = 1; $i < count($game); $i++) {
            if ($game[$i] <= $game[$i - 1] + 2) {
                $run++;
            } else {
                if ($run >= 2) {
                    $clusters += $run;
                }

                $run = 1;
            }
        }

        if ($run >= 2) {
            $clusters += $run;
        }

        return $clusters;
    }

    protected function extractNumbers(LotofacilConcurso $concurso): array
    {
        $numbers = [];

        for ($i = 1; $i <= 15; $i++) {
            $numbers[] = (int) $concurso->{'bola' . $i};
        }

        sort($numbers);

        return $numbers;
    }
}