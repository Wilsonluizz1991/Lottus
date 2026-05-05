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

            $explosionQuality = $this->explosionQuality(
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
                lineDistribution: $lineDistribution,
                quadrantDistribution: $quadrantDistribution,
                frameCount: $frameCount,
                clusterStrength: $clusterStrength
            );

            $aestheticPenalty = $this->aestheticPenalty(
                structureQuality: $structureQuality,
                survivalQuality: $survivalQuality,
                explosionQuality: $explosionQuality,
                sum: $sum,
                oddCount: $oddCount,
                repeatCount: $repeatCount,
                longestSequence: $longestSequence,
                clusterStrength: $clusterStrength,
                lineDistribution: $lineDistribution,
                quadrantDistribution: $quadrantDistribution
            );

            $baseScore = $this->baseScore(
                frequencyQuality: $frequencyQuality,
                delayQuality: $delayQuality,
                cycleQuality: $cycleQuality,
                correlationQuality: $correlationQuality,
                structureQuality: $structureQuality,
                survivalQuality: $survivalQuality,
                explosionQuality: $explosionQuality
            );

            $eliteBonus = $this->eliteBonus(
                frequencyQuality: $frequencyQuality,
                delayQuality: $delayQuality,
                cycleQuality: $cycleQuality,
                correlationQuality: $correlationQuality,
                structureQuality: $structureQuality,
                survivalQuality: $survivalQuality,
                explosionQuality: $explosionQuality,
                repeatCount: $repeatCount,
                cycleHits: $cycleHits,
                sum: $sum,
                oddCount: $oddCount,
                longestSequence: $longestSequence,
                clusterStrength: $clusterStrength,
                lineDistribution: $lineDistribution,
                quadrantDistribution: $quadrantDistribution,
                frameCount: $frameCount
            );

            $score = $this->explosiveScore(
                baseScore: $baseScore,
                eliteBonus: $eliteBonus,
                aestheticPenalty: $aestheticPenalty,
                frequencyQuality: $frequencyQuality,
                delayQuality: $delayQuality,
                cycleQuality: $cycleQuality,
                correlationQuality: $correlationQuality,
                structureQuality: $structureQuality,
                survivalQuality: $survivalQuality,
                explosionQuality: $explosionQuality,
                repeatCount: $repeatCount,
                cycleHits: $cycleHits,
                sum: $sum,
                oddCount: $oddCount,
                longestSequence: $longestSequence,
                frameCount: $frameCount,
                clusterStrength: $clusterStrength
            );

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
                'explosion_quality' => round($explosionQuality, 6),
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

    protected function baseScore(
        float $frequencyQuality,
        float $delayQuality,
        float $cycleQuality,
        float $correlationQuality,
        float $structureQuality,
        float $survivalQuality,
        float $explosionQuality
    ): float {
        return
            ($frequencyQuality * 28.0) +
            ($delayQuality * 20.0) +
            ($cycleQuality * 24.0) +
            ($correlationQuality * 115.0) +
            ($structureQuality * 10.0) +
            ($survivalQuality * 36.0) +
            ($explosionQuality * 180.0);
    }

    protected function explosiveScore(
        float $baseScore,
        float $eliteBonus,
        float $aestheticPenalty,
        float $frequencyQuality,
        float $delayQuality,
        float $cycleQuality,
        float $correlationQuality,
        float $structureQuality,
        float $survivalQuality,
        float $explosionQuality,
        int $repeatCount,
        int $cycleHits,
        int $sum,
        int $oddCount,
        int $longestSequence,
        int $frameCount,
        float $clusterStrength
    ): float {
        $explosionFactor = pow(max(0.0, $explosionQuality), 2.35);
        $survivalFactor = pow(max(0.0, $survivalQuality), 1.12);
        $correlationFactor = pow(max(0.0, $correlationQuality), 2.0);
        $frequencyFactor = pow(max(0.0, $frequencyQuality), 1.35);
        $cycleFactor = pow(max(0.0, $cycleQuality), 1.15);

        $score =
            ($explosionFactor * 1450.0) +
            ($survivalFactor * 260.0) +
            ($correlationFactor * 460.0) +
            ($frequencyFactor * 135.0) +
            ($cycleFactor * 90.0) +
            ($delayQuality * 75.0) +
            ($eliteBonus * 8.0) +
            ($baseScore * 0.80);

        if ($explosionQuality >= 0.72) {
            $score += 1400.0;
        } elseif ($explosionQuality >= 0.66) {
            $score += 900.0;
        } elseif ($explosionQuality >= 0.60) {
            $score += 520.0;
        } elseif ($explosionQuality >= 0.54) {
            $score += 220.0;
        }

        if ($explosionQuality >= 0.60 && $correlationQuality >= 0.585) {
            $score += 650.0;
        }

        if ($explosionQuality >= 0.58 && $survivalQuality >= 0.70 && $correlationQuality >= 0.585) {
            $score += 520.0;
        }

        if ($frequencyQuality >= 0.43 && $frequencyQuality <= 0.66 && $delayQuality >= 0.16 && $delayQuality <= 0.42 && $correlationQuality >= 0.585) {
            $score += 360.0;
        }

        if ($repeatCount >= 7 && $repeatCount <= 10) {
            $score += 220.0;
        } elseif ($repeatCount === 6 || $repeatCount === 11 || $repeatCount === 12) {
            $score += 90.0;
        }

        if ($cycleHits <= 1) {
            $score += 130.0;
        } elseif ($cycleHits >= 2 && $cycleHits <= 5) {
            $score += 80.0;
        }

        if ($sum >= 170 && $sum <= 236) {
            $score += 140.0;
        } elseif ($sum >= 145 && $sum <= 245) {
            $score += 55.0;
        }

        if ($oddCount >= 7 && $oddCount <= 10) {
            $score += 90.0;
        } elseif ($oddCount >= 5 && $oddCount <= 11) {
            $score += 35.0;
        }

        if ($longestSequence >= 5 && $longestSequence <= 9) {
            $score += 95.0;
        } elseif ($longestSequence >= 3 && $longestSequence <= 10) {
            $score += 40.0;
        }

        if ($clusterStrength >= 12 && $clusterStrength <= 17) {
            $score += 85.0;
        } elseif ($clusterStrength >= 8 && $clusterStrength <= 18) {
            $score += 35.0;
        }

        if ($frameCount >= 8 && $frameCount <= 13) {
            $score += 40.0;
        }

        if ($structureQuality >= 0.72 && $survivalQuality >= 0.78 && $explosionQuality < 0.56) {
            $score -= 420.0;
        }

        if ($survivalQuality >= 0.82 && $explosionQuality < 0.58) {
            $score -= 360.0;
        }

        if ($structureQuality >= 0.88 && $explosionQuality < 0.58) {
            $score -= 260.0;
        }

        $score -= ($aestheticPenalty * 18.0);

        return $score;
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
            $quality += 0.10;
        } elseif ($sum >= ($sumMin - 18) && $sum <= ($sumMax + 18)) {
            $quality += 0.08;
        } elseif ($sum >= 140 && $sum <= 245) {
            $quality += 0.05;
        }

        if ($oddCount >= $oddMin && $oddCount <= $oddMax) {
            $quality += 0.09;
        } elseif ($oddCount >= 5 && $oddCount <= 10) {
            $quality += 0.08;
        } elseif ($oddCount >= 4 && $oddCount <= 11) {
            $quality += 0.04;
        }

        if ($repeatCount >= $repeatMin && $repeatCount <= $repeatMax) {
            $quality += 0.09;
        } elseif ($repeatCount >= 6 && $repeatCount <= 13) {
            $quality += 0.08;
        } elseif ($repeatCount >= 5 && $repeatCount <= 14) {
            $quality += 0.04;
        }

        if ($cycleHits >= 1 && $cycleHits <= 6) {
            $quality += 0.09;
        } elseif ($cycleHits >= 7) {
            $quality += 0.04;
        }

        if ($longestSequence >= 2 && $longestSequence <= $maxSequence) {
            $quality += 0.08;
        } elseif ($longestSequence <= 9) {
            $quality += 0.05;
        }

        $maxLine = max($lineDistribution);
        $minLine = min($lineDistribution);

        if ($maxLine <= 5 && $minLine >= 1) {
            $quality += 0.07;
        } elseif ($maxLine <= 6) {
            $quality += 0.04;
        }

        $maxQuadrant = max($quadrantDistribution);
        $minQuadrant = min($quadrantDistribution);

        if ($maxQuadrant <= 6 && $minQuadrant >= 1) {
            $quality += 0.06;
        } elseif ($maxQuadrant <= 7) {
            $quality += 0.04;
        }

        if ($frameCount >= 6 && $frameCount <= 14) {
            $quality += 0.05;
        }

        if ($clusterStrength >= 7 && $clusterStrength <= 17) {
            $quality += 0.04;
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

        if ($frequencyQuality >= 0.50) {
            $quality += 0.08;
        }

        if ($delayQuality >= 0.18) {
            $quality += 0.08;
        }

        if ($cycleQuality >= 0.45) {
            $quality += 0.08;
        }

        if ($correlationQuality >= 0.58) {
            $quality += 0.10;
        }

        if ($repeatCount >= 6 && $repeatCount <= 13) {
            $quality += 0.11;
        }

        if ($cycleHits >= 1 && $cycleHits <= 6) {
            $quality += 0.09;
        }

        if ($sum >= 145 && $sum <= 240) {
            $quality += 0.07;
        }

        if ($oddCount >= 4 && $oddCount <= 11) {
            $quality += 0.07;
        }

        if ($longestSequence >= 2 && $longestSequence <= 9) {
            $quality += 0.06;
        }

        if (max($lineDistribution) <= 6) {
            $quality += 0.05;
        }

        if (max($quadrantDistribution) <= 7) {
            $quality += 0.05;
        }

        if ($frameCount >= 5 && $frameCount <= 15) {
            $quality += 0.04;
        }

        if ($clusterStrength >= 6 && $clusterStrength <= 18) {
            $quality += 0.04;
        }

        return min(1.0, $quality);
    }

    protected function explosionQuality(
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
        array $lineDistribution,
        array $quadrantDistribution,
        int $frameCount,
        float $clusterStrength
    ): float {
        $quality = 0.0;

        if ($correlationQuality >= 0.585) {
            $quality += 0.16;
        }

        if ($frequencyQuality >= 0.43 && $frequencyQuality <= 0.66) {
            $quality += 0.13;
        } elseif ($frequencyQuality > 0.66) {
            $quality += 0.07;
        }

        if ($delayQuality >= 0.16 && $delayQuality <= 0.42) {
            $quality += 0.13;
        } elseif ($delayQuality > 0.42) {
            $quality += 0.08;
        }

        if ($cycleQuality >= 0.45) {
            $quality += 0.10;
        }

        if ($repeatCount >= 7 && $repeatCount <= 10) {
            $quality += 0.15;
        } elseif ($repeatCount === 6 || $repeatCount === 11 || $repeatCount === 12) {
            $quality += 0.09;
        }

        if ($cycleHits <= 1) {
            $quality += 0.10;
        } elseif ($cycleHits >= 2 && $cycleHits <= 5) {
            $quality += 0.08;
        }

        if ($sum >= 170 && $sum <= 236) {
            $quality += 0.10;
        } elseif ($sum >= 150 && $sum <= 245) {
            $quality += 0.06;
        }

        if ($oddCount >= 7 && $oddCount <= 10) {
            $quality += 0.08;
        } elseif ($oddCount >= 5 && $oddCount <= 11) {
            $quality += 0.04;
        }

        if ($longestSequence >= 5 && $longestSequence <= 9) {
            $quality += 0.08;
        } elseif ($longestSequence >= 3 && $longestSequence <= 10) {
            $quality += 0.05;
        }

        if ($clusterStrength >= 12 && $clusterStrength <= 17) {
            $quality += 0.07;
        } elseif ($clusterStrength >= 8 && $clusterStrength <= 18) {
            $quality += 0.04;
        }

        if (max($lineDistribution) >= 5 && max($lineDistribution) <= 6) {
            $quality += 0.04;
        }

        if ($frameCount >= 8 && $frameCount <= 13) {
            $quality += 0.04;
        }

        if ($structureQuality >= 0.58 && $survivalQuality >= 0.62) {
            $quality += 0.05;
        }

        return min(1.0, $quality);
    }

    protected function aestheticPenalty(
        float $structureQuality,
        float $survivalQuality,
        float $explosionQuality,
        int $sum,
        int $oddCount,
        int $repeatCount,
        int $longestSequence,
        float $clusterStrength,
        array $lineDistribution,
        array $quadrantDistribution
    ): float {
        $penalty = 0.0;

        if ($structureQuality >= 0.88 && $explosionQuality < 0.58) {
            $penalty += 1.4;
        }

        if ($survivalQuality >= 0.80 && $explosionQuality < 0.60) {
            $penalty += 2.2;
        }

        if ($sum >= 180 && $sum <= 205 && $oddCount >= 7 && $oddCount <= 8 && $explosionQuality < 0.62) {
            $penalty += 0.9;
        }

        if ($repeatCount >= 8 && $repeatCount <= 10 && $longestSequence >= 3 && $longestSequence <= 5 && $explosionQuality < 0.62) {
            $penalty += 0.8;
        }

        if ($clusterStrength >= 13 && $explosionQuality < 0.56) {
            $penalty += 0.6;
        }

        if (max($lineDistribution) <= 4 && min($lineDistribution) >= 2 && $explosionQuality < 0.58) {
            $penalty += 0.5;
        }

        if (max($quadrantDistribution) <= 5 && min($quadrantDistribution) >= 2 && $explosionQuality < 0.58) {
            $penalty += 0.5;
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
        float $explosionQuality,
        int $repeatCount,
        int $cycleHits,
        int $sum,
        int $oddCount,
        int $longestSequence,
        float $clusterStrength,
        array $lineDistribution,
        array $quadrantDistribution,
        int $frameCount
    ): float {
        $bonus = 0.0;

        if ($explosionQuality >= 0.72) {
            $bonus += 18.0;
        } elseif ($explosionQuality >= 0.66) {
            $bonus += 13.5;
        } elseif ($explosionQuality >= 0.60) {
            $bonus += 8.5;
        } elseif ($explosionQuality >= 0.54) {
            $bonus += 4.0;
        }

        if ($survivalQuality >= 0.72 && $explosionQuality >= 0.58) {
            $bonus += 4.8;
        }

        if ($survivalQuality >= 0.80 && $explosionQuality < 0.58) {
            $bonus -= 7.5;
        }

        if ($frequencyQuality >= 0.43 && $frequencyQuality <= 0.62 && $correlationQuality >= 0.585) {
            $bonus += 6.0;
        }

        if ($delayQuality >= 0.16 && $delayQuality <= 0.42 && $cycleQuality >= 0.45) {
            $bonus += 5.2;
        }

        if ($correlationQuality >= 0.587 && $explosionQuality >= 0.58) {
            $bonus += 6.0;
        }

        if ($repeatCount >= 7 && $repeatCount <= 10) {
            $bonus += 4.8;
        } elseif ($repeatCount === 6 || $repeatCount === 11 || $repeatCount === 12) {
            $bonus += 2.2;
        }

        if ($cycleHits === 0) {
            $bonus += 2.8;
        } elseif ($cycleHits >= 1 && $cycleHits <= 5) {
            $bonus += 1.8;
        }

        if ($sum >= 170 && $sum <= 236) {
            $bonus += 2.8;
        } elseif ($sum >= 150 && $sum <= 245) {
            $bonus += 1.2;
        }

        if ($oddCount >= 7 && $oddCount <= 10) {
            $bonus += 2.4;
        } elseif ($oddCount >= 5 && $oddCount <= 11) {
            $bonus += 1.0;
        }

        if ($longestSequence >= 5 && $longestSequence <= 9) {
            $bonus += 2.6;
        } elseif ($longestSequence >= 3 && $longestSequence <= 10) {
            $bonus += 1.0;
        }

        if ($clusterStrength >= 12 && $clusterStrength <= 17) {
            $bonus += 2.2;
        } elseif ($clusterStrength >= 8 && $clusterStrength <= 18) {
            $bonus += 0.9;
        }

        if (max($lineDistribution) >= 5 && max($lineDistribution) <= 6) {
            $bonus += 1.1;
        }

        if ($frameCount >= 8 && $frameCount <= 13) {
            $bonus += 0.9;
        }

        if (
            $explosionQuality >= 0.62 &&
            $correlationQuality >= 0.585 &&
            $frequencyQuality >= 0.43 &&
            $delayQuality >= 0.16
        ) {
            $bonus += 9.0;
        }

        if (
            $structureQuality >= 0.72 &&
            $survivalQuality >= 0.78 &&
            $explosionQuality < 0.56
        ) {
            $bonus -= 6.5;
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