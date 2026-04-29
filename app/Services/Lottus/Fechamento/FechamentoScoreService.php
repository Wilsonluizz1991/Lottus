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

            $baseScore =
                ($frequencyQuality * 8.4) +
                ($delayQuality * 7.2) +
                ($cycleQuality * 7.8) +
                ($correlationQuality * 10.5) +
                ($structureQuality * 5.5);

            $eliteBonus = $this->eliteBonus(
                frequencyQuality: $frequencyQuality,
                delayQuality: $delayQuality,
                cycleQuality: $cycleQuality,
                correlationQuality: $correlationQuality,
                structureQuality: $structureQuality,
                repeatCount: $repeatCount,
                cycleHits: $cycleHits,
                sum: $sum,
                oddCount: $oddCount,
                longestSequence: $longestSequence,
                clusterStrength: $clusterStrength
            );

            $score = $baseScore + $eliteBonus;

            $scored[] = [
                'dezenas' => $game,
                'score' => round($score, 6),
                'base_score' => round($baseScore, 6),
                'elite_bonus' => round($eliteBonus, 6),
                'frequency_quality' => round($frequencyQuality, 6),
                'delay_quality' => round($delayQuality, 6),
                'cycle_quality' => round($cycleQuality, 6),
                'correlation_quality' => round($correlationQuality, 6),
                'structure_quality' => round($structureQuality, 6),
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
            $quality += 0.18;
        } elseif ($sum >= ($sumMin - 10) && $sum <= ($sumMax + 10)) {
            $quality += 0.10;
        }

        if ($oddCount >= $oddMin && $oddCount <= $oddMax) {
            $quality += 0.15;
        } elseif ($oddCount >= 5 && $oddCount <= 10) {
            $quality += 0.08;
        }

        if ($repeatCount >= $repeatMin && $repeatCount <= $repeatMax) {
            $quality += 0.16;
        } elseif ($repeatCount >= 6 && $repeatCount <= 13) {
            $quality += 0.08;
        }

        if ($cycleHits >= 2) {
            $quality += 0.10;
        }

        if ($longestSequence >= 3 && $longestSequence <= $maxSequence) {
            $quality += 0.12;
        } elseif ($longestSequence <= 8) {
            $quality += 0.06;
        }

        $maxLine = max($lineDistribution);
        $minLine = min($lineDistribution);

        if ($maxLine <= 4 && $minLine >= 2) {
            $quality += 0.10;
        } elseif ($maxLine <= 5 && $minLine >= 1) {
            $quality += 0.06;
        }

        $maxQuadrant = max($quadrantDistribution);
        $minQuadrant = min($quadrantDistribution);

        if ($maxQuadrant <= 5 && $minQuadrant >= 2) {
            $quality += 0.08;
        } elseif ($maxQuadrant <= 6 && $minQuadrant >= 1) {
            $quality += 0.04;
        }

        if ($frameCount >= 8 && $frameCount <= 11) {
            $quality += 0.07;
        } elseif ($frameCount === 7 || $frameCount === 12) {
            $quality += 0.03;
        }

        if ($clusterStrength >= 8) {
            $quality += 0.04;
        }

        return min(1.0, $quality);
    }

    protected function eliteBonus(
        float $frequencyQuality,
        float $delayQuality,
        float $cycleQuality,
        float $correlationQuality,
        float $structureQuality,
        int $repeatCount,
        int $cycleHits,
        int $sum,
        int $oddCount,
        int $longestSequence,
        float $clusterStrength
    ): float {
        $bonus = 0.0;

        if ($correlationQuality >= 0.70 && $frequencyQuality >= 0.55) {
            $bonus += 3.8;
        }

        if ($cycleQuality >= 0.55 && $delayQuality >= 0.50) {
            $bonus += 2.8;
        }

        if ($repeatCount >= 8 && $repeatCount <= 11) {
            $bonus += 3.2;
        }

        if ($cycleHits >= 3) {
            $bonus += 2.4;
        }

        if ($sum >= 175 && $sum <= 215) {
            $bonus += 1.6;
        }

        if ($oddCount >= 6 && $oddCount <= 9) {
            $bonus += 1.4;
        }

        if ($longestSequence >= 3 && $longestSequence <= 6) {
            $bonus += 1.5;
        }

        if ($clusterStrength >= 9) {
            $bonus += 1.2;
        }

        if (
            $correlationQuality >= 0.72 &&
            $frequencyQuality >= 0.58 &&
            $structureQuality >= 0.65
        ) {
            $bonus += 5.0;
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