<?php

namespace App\Services\Lottus\Generation;

use App\Models\LotofacilConcurso;
use Illuminate\Support\Collection;

class GameScoringService
{
    public function rank(
        array $candidates,
        array $frequencyContext,
        array $delayContext,
        array $correlationContext,
        array $structureContext,
        LotofacilConcurso $concursoBase,
        Collection|array|null $historico = null
    ): array {
        $ultimoConcurso = $this->extractNumbers($concursoBase);
        $historicalMasks = $this->historicalMasks($historico);
        $ranked = [];

        foreach ($candidates as $candidate) {
            $game = $candidate['dezenas'] ?? $candidate;
            $profile = $candidate['profile'] ?? 'balanced';
            $strategy = $candidate['strategy'] ?? $profile;
            $cycleMissing = $candidate['cycle_missing'] ?? [];

            $game = array_values(array_unique(array_map('intval', $game)));
            sort($game);

            if (count($game) !== 15) {
                continue;
            }

            $frequencyScore = $this->sumMetric($game, $frequencyContext['scores'] ?? []);
            $delayScore = $this->sumMetric($game, $delayContext['scores'] ?? []);
            $correlationScore = $this->pairMetric($game, $correlationContext['pair_scores'] ?? []);

            $repeatCount = count(array_intersect($game, $ultimoConcurso));
            $cycleHit = count(array_intersect($game, $cycleMissing));

            $oddCount = count(array_filter($game, fn ($n) => $n % 2 !== 0));
            $evenCount = 15 - $oddCount;
            $sum = array_sum($game);
            $lineDistribution = $this->lineDistribution($game);
            $longestSequence = $this->longestSequence($game);
            $frameCount = $this->frameCount($game);
            $middleCount = 15 - $frameCount;
            $clusterStrength = $this->clusterStrength($game);
            $quadrantDistribution = $this->quadrantDistribution($game);
            $historicalPerformance = $this->historicalPerformance($game, $historicalMasks);

            $frequencyQuality = $this->metricQuality($frequencyScore);
            $delayQuality = $this->metricQuality($delayScore);
            $correlationQuality = $this->metricQuality($correlationScore);
            $historicalPeakQuality = $this->historicalPeakQuality($historicalPerformance);

            $repeatQuality = $this->repeatQuality($repeatCount);
            $cycleQuality = $this->cycleQuality($cycleHit, $cycleMissing);
            $sumQuality = $this->sumQuality($sum);
            $parityQuality = $this->parityQuality($oddCount, $evenCount);
            $sequenceQuality = $this->sequenceQuality($longestSequence);
            $lineQuality = $this->lineQuality($lineDistribution);
            $frameQuality = $this->frameQuality($frameCount, $middleCount);
            $clusterQuality = $this->clusterQuality($clusterStrength);
            $quadrantQuality = $this->quadrantQuality($quadrantDistribution);
            $ceilingScore = $this->ceilingScore(
                $strategy,
                $frequencyQuality,
                $delayQuality,
                $correlationQuality,
                $repeatQuality,
                $cycleQuality,
                $historicalPeakQuality,
                $repeatCount,
                $cycleHit,
                $clusterStrength,
                $sum,
                $oddCount,
                $longestSequence
            );

            $statScore =
                ($frequencyQuality * 8.60) +
                ($delayQuality * 7.20) +
                ($correlationQuality * 10.80) +
                ($repeatQuality * 9.20) +
                ($cycleQuality * 6.80) +
                ($historicalPeakQuality * 9.60) +
                ($ceilingScore * 0.34);

            $structureScore =
                ($sumQuality * 1.80) +
                ($parityQuality * 1.40) +
                ($sequenceQuality * 1.80) +
                ($lineQuality * 1.10) +
                ($frameQuality * 1.20) +
                ($clusterQuality * 1.80) +
                ($quadrantQuality * 1.00);

            $huntScore = ($statScore * 1.45) + ($structureScore * 0.65);
            $huntScore += $ceilingScore;

            /*
            |--------------------------------------------------------------------------
            | HUNT 14+ AGGRESSIVE BOOST
            |--------------------------------------------------------------------------
            */

            if ($repeatCount >= 8 && $repeatCount <= 11) {
                $huntScore += 9.50;
            } elseif ($repeatCount === 7 || $repeatCount === 12) {
                $huntScore += 5.40;
            }

            if ($cycleHit >= 4) {
                $huntScore += 8.20;
            } elseif ($cycleHit === 3) {
                $huntScore += 6.20;
            } elseif ($cycleHit === 2) {
                $huntScore += 3.40;
            }

            if (
                $correlationQuality >= 0.75 &&
                $frequencyQuality >= 0.68 &&
                $delayQuality >= 0.50
            ) {
                $huntScore += 12.00;
            }

            if (
                $repeatCount >= 8 &&
                $repeatCount <= 11 &&
                $clusterStrength >= 9
            ) {
                $huntScore += 7.50;
            }

            if (
                $sum >= 175 &&
                $sum <= 215
            ) {
                $huntScore += 4.80;
            }

            if (
                $oddCount >= 6 &&
                $oddCount <= 9
            ) {
                $huntScore += 4.20;
            }

            if (
                $longestSequence >= 3 &&
                $longestSequence <= 6
            ) {
                $huntScore += 4.60;
            }

            if (
                $quadrantQuality >= 0.70 &&
                $lineQuality >= 0.70
            ) {
                $huntScore += 6.20;
            }

            if (
                $frameQuality >= 0.75 &&
                $clusterQuality >= 0.70
            ) {
                $huntScore += 5.80;
            }

            if (
                $frequencyQuality >= 0.72 &&
                $correlationQuality >= 0.72 &&
                $repeatQuality >= 0.80
            ) {
                $huntScore += 15.00;
            }

            /*
            |--------------------------------------------------------------------------
            | HISTORICAL PEAK CEILING
            |--------------------------------------------------------------------------
            |
            | Usa apenas concursos anteriores ao concurso base para premiar candidatos
            | que ja demonstraram teto alto em walk-forward. Nao usa resultado futuro.
            |
            */

            if (($historicalPerformance['max_hits'] ?? 0) >= 15) {
                $huntScore += 95.00;
            } elseif (($historicalPerformance['max_hits'] ?? 0) >= 14) {
                $huntScore += 58.00;
            } elseif (($historicalPerformance['max_hits'] ?? 0) >= 13) {
                $huntScore += 22.00;
            }

            if (($historicalPerformance['hits_14_plus'] ?? 0) >= 2) {
                $huntScore += 34.00;
            } elseif (($historicalPerformance['hits_14_plus'] ?? 0) === 1) {
                $huntScore += 18.00;
            }

            if (($historicalPerformance['hits_13_plus'] ?? 0) >= 8) {
                $huntScore += 14.00;
            } elseif (($historicalPerformance['hits_13_plus'] ?? 0) >= 4) {
                $huntScore += 8.00;
            }

            /*
            |--------------------------------------------------------------------------
            | EXTREME 14+ MULTIPLIER
            |--------------------------------------------------------------------------
            */

            $eliteFactor = 1.0;

            if (
                $repeatCount >= 8 &&
                $repeatCount <= 11 &&
                $correlationQuality >= 0.70 &&
                $frequencyQuality >= 0.68
            ) {
                $eliteFactor += 0.28;
            }

            if (
                ($historicalPerformance['max_hits'] ?? 0) >= 14 &&
                $historicalPeakQuality >= 0.60
            ) {
                $eliteFactor += 0.16;
            }

            if (
                $clusterStrength >= 9 &&
                $quadrantQuality >= 0.70
            ) {
                $eliteFactor += 0.12;
            }

            $huntScore *= $eliteFactor;

            $profileBoost = match ($profile) {
                'aggressive', 'explosive', 'high_ceiling', 'explosive_hybrid' => 1.12,
                'correlation_cluster', 'historical_replay' => 1.10,
                'strategic_repeat', 'controlled_delay' => 1.08,
                'anti_mean' => 1.05,
                'balanced' => 1.00,
                default => 1.04,
            };

            $score = $huntScore * $profileBoost;

            $ranked[] = [
                'dezenas' => $game,
                'profile' => $profile,
                'strategy' => $strategy,
                'score' => round($score, 6),
                'extreme_score' => round($huntScore, 6),
                'stat_score' => round($statScore, 6),
                'structure_score' => round($structureScore, 6),
                'ceiling_score' => round($ceilingScore, 6),
                'near_15_score' => round($ceilingScore + ($historicalPeakQuality * 22.0), 6),
                'pares' => $evenCount,
                'impares' => $oddCount,
                'soma' => $sum,
                'repetidas_ultimo_concurso' => $repeatCount,
                'cycle_hits' => $cycleHit,
                'historical_peak_score' => round($historicalPeakQuality, 6),
                'historical_max_hits' => (int) ($historicalPerformance['max_hits'] ?? 0),
                'historical_13_plus' => (int) ($historicalPerformance['hits_13_plus'] ?? 0),
                'historical_14_plus' => (int) ($historicalPerformance['hits_14_plus'] ?? 0),
                'analise' => [
                    'profile' => $profile,
                    'strategy' => $strategy,
                    'repeat' => $repeatCount,
                    'cycle_hits' => $cycleHit,
                    'pares' => $evenCount,
                    'impares' => $oddCount,
                    'soma' => $sum,
                    'sequencia_maxima' => $longestSequence,
                    'linhas' => $lineDistribution,
                    'moldura' => $frameCount,
                    'miolo' => $middleCount,
                    'cluster_strength' => round($clusterStrength, 4),
                    'quadrantes' => $quadrantDistribution,
                    'frequency_score' => round($frequencyScore, 6),
                    'delay_score' => round($delayScore, 6),
                    'correlation_score' => round($correlationScore, 6),
                    'frequency_quality' => round($frequencyQuality, 4),
                    'delay_quality' => round($delayQuality, 4),
                    'correlation_quality' => round($correlationQuality, 4),
                    'repeat_quality' => round($repeatQuality, 4),
                    'cycle_quality' => round($cycleQuality, 4),
                    'historical_peak_quality' => round($historicalPeakQuality, 4),
                    'ceiling_score' => round($ceilingScore, 4),
                    'near_15_score' => round($ceilingScore + ($historicalPeakQuality * 22.0), 4),
                    'historical_max_hits' => (int) ($historicalPerformance['max_hits'] ?? 0),
                    'historical_13_plus' => (int) ($historicalPerformance['hits_13_plus'] ?? 0),
                    'historical_14_plus' => (int) ($historicalPerformance['hits_14_plus'] ?? 0),
                    'sum_quality' => round($sumQuality, 4),
                    'parity_quality' => round($parityQuality, 4),
                    'sequence_quality' => round($sequenceQuality, 4),
                    'line_quality' => round($lineQuality, 4),
                    'frame_quality' => round($frameQuality, 4),
                    'cluster_quality' => round($clusterQuality, 4),
                    'quadrant_quality' => round($quadrantQuality, 4),
                ],
            ];
        }

        usort($ranked, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $ranked;
    }

    protected function ceilingScore(
        string $strategy,
        float $frequencyQuality,
        float $delayQuality,
        float $correlationQuality,
        float $repeatQuality,
        float $cycleQuality,
        float $historicalPeakQuality,
        int $repeatCount,
        int $cycleHit,
        float $clusterStrength,
        int $sum,
        int $oddCount,
        int $longestSequence
    ): float {
        $score = 0.0;

        $score += $correlationQuality * 12.0;
        $score += $historicalPeakQuality * 18.0;

        if ($repeatCount >= 8 && $repeatCount <= 11) {
            $score += 10.0;
        } elseif ($repeatCount === 7 || $repeatCount === 12) {
            $score += 5.0;
        }

        if ($cycleHit >= 4) {
            $score += 8.5;
        } elseif ($cycleHit >= 2) {
            $score += 5.0;
        }

        if (
            $frequencyQuality >= 0.70 &&
            $correlationQuality >= 0.72 &&
            $repeatQuality >= 0.76
        ) {
            $score += 14.0;
        }

        if (
            $delayQuality >= 0.62 &&
            $cycleQuality >= 0.70 &&
            $correlationQuality >= 0.68
        ) {
            $score += 10.0;
        }

        if ($clusterStrength >= 10 && $longestSequence >= 3 && $longestSequence <= 7) {
            $score += 7.5;
        }

        if (($sum >= 150 && $sum <= 169) || ($sum >= 216 && $sum <= 235)) {
            $score += 3.0;
        }

        if ($oddCount === 5 || $oddCount === 10) {
            $score += 2.5;
        }

        $score *= match ($strategy) {
            'elite_high_ceiling', 'explosive_hybrid' => 1.16,
            'correlation_cluster', 'historical_replay' => 1.13,
            'strategic_repeat', 'controlled_delay' => 1.09,
            'anti_mean_high_ceiling' => 1.06,
            default => 1.00,
        };

        return $score;
    }

    protected function historicalMasks(Collection|array|null $historico): array
    {
        if (! $historico) {
            return [];
        }

        $items = $historico instanceof Collection ? $historico->values()->all() : array_values($historico);
        $items = array_slice($items, -180);
        $masks = [];

        foreach ($items as $contest) {
            $numbers = $this->extractNumbersFromMixed($contest);

            if (count($numbers) !== 15) {
                continue;
            }

            $masks[] = $this->numberMask($numbers);
        }

        return $masks;
    }

    protected function historicalPerformance(array $game, array $historicalMasks): array
    {
        if (empty($historicalMasks)) {
            return [
                'max_hits' => 0,
                'hits_13_plus' => 0,
                'hits_14_plus' => 0,
            ];
        }

        $mask = $this->numberMask($game);
        $maxHits = 0;
        $hits13Plus = 0;
        $hits14Plus = 0;

        foreach ($historicalMasks as $historicalMask) {
            $hits = $this->bitCount($mask & $historicalMask);
            $maxHits = max($maxHits, $hits);

            if ($hits >= 13) {
                $hits13Plus++;
            }

            if ($hits >= 14) {
                $hits14Plus++;
            }
        }

        return [
            'max_hits' => $maxHits,
            'hits_13_plus' => $hits13Plus,
            'hits_14_plus' => $hits14Plus,
        ];
    }

    protected function historicalPeakQuality(array $performance): float
    {
        $maxHits = (int) ($performance['max_hits'] ?? 0);
        $hits13Plus = (int) ($performance['hits_13_plus'] ?? 0);
        $hits14Plus = (int) ($performance['hits_14_plus'] ?? 0);

        $maxScore = match (true) {
            $maxHits >= 15 => 1.00,
            $maxHits >= 14 => 0.82,
            $maxHits >= 13 => 0.52,
            $maxHits >= 12 => 0.24,
            default => 0.0,
        };

        $densityScore = min(1.0, ($hits14Plus * 0.22) + ($hits13Plus * 0.035));

        return max(0.0, min(1.0, ($maxScore * 0.72) + ($densityScore * 0.28)));
    }

    protected function numberMask(array $numbers): int
    {
        $mask = 0;

        foreach ($numbers as $number) {
            $number = (int) $number;

            if ($number >= 1 && $number <= 25) {
                $mask |= 1 << ($number - 1);
            }
        }

        return $mask;
    }

    protected function bitCount(int $value): int
    {
        $count = 0;

        while ($value > 0) {
            $value &= ($value - 1);
            $count++;
        }

        return $count;
    }

    protected function extractNumbersFromMixed(mixed $contest): array
    {
        if ($contest instanceof LotofacilConcurso) {
            return $this->extractNumbers($contest);
        }

        if (is_array($contest)) {
            $numbers = $contest['dezenas'] ?? $contest['numbers'] ?? null;

            if (is_array($numbers)) {
                return $this->normalizeNumbers($numbers);
            }

            $numbers = [];

            for ($i = 1; $i <= 15; $i++) {
                $key = 'bola' . $i;

                if (isset($contest[$key])) {
                    $numbers[] = (int) $contest[$key];
                }
            }

            return $this->normalizeNumbers($numbers);
        }

        return [];
    }

    protected function sumMetric(array $game, array $scores): float
    {
        $total = 0.0;

        foreach ($game as $number) {
            $total += (float) ($scores[$number] ?? 0.0);
        }

        return $total;
    }

    protected function pairMetric(array $game, array $pairScores): float
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

        return $count ? ($total / $count) : 0.0;
    }

    protected function metricQuality(float $value): float
    {
        return 1.0 / (1.0 + exp(-$value));
    }

    protected function repeatQuality(int $repeatCount): float
    {
        return match (true) {
            $repeatCount >= 8 && $repeatCount <= 11 => 1.00,
            $repeatCount === 7 || $repeatCount === 12 => 0.76,
            $repeatCount === 6 || $repeatCount === 13 => 0.42,
            default => 0.16,
        };
    }

    protected function cycleQuality(int $cycleHit, array $cycleMissing): float
    {
        if (empty($cycleMissing)) {
            return 0.50;
        }

        return match (true) {
            $cycleHit >= 4 => 1.00,
            $cycleHit === 3 => 0.88,
            $cycleHit === 2 => 0.74,
            $cycleHit === 1 => 0.56,
            default => 0.22,
        };
    }

    protected function sumQuality(int $sum): float
    {
        return match (true) {
            $sum >= 170 && $sum <= 215 => 1.00,
            $sum >= 160 && $sum <= 225 => 0.82,
            $sum >= 150 && $sum <= 235 => 0.48,
            default => 0.18,
        };
    }

    protected function parityQuality(int $oddCount, int $evenCount): float
    {
        return match (true) {
            ($oddCount === 7 && $evenCount === 8) || ($oddCount === 8 && $evenCount === 7) => 1.00,
            ($oddCount === 6 && $evenCount === 9) || ($oddCount === 9 && $evenCount === 6) => 0.82,
            ($oddCount === 5 && $evenCount === 10) || ($oddCount === 10 && $evenCount === 5) => 0.54,
            default => 0.22,
        };
    }

    protected function sequenceQuality(int $longestSequence): float
    {
        return match (true) {
            $longestSequence >= 3 && $longestSequence <= 6 => 1.00,
            $longestSequence === 2 || $longestSequence === 7 => 0.68,
            $longestSequence === 8 => 0.38,
            default => 0.16,
        };
    }

    protected function lineQuality(array $lineDistribution): float
    {
        $maxLine = max($lineDistribution);
        $minLine = min($lineDistribution);

        return match (true) {
            $maxLine <= 4 && $minLine >= 2 => 1.00,
            $maxLine <= 5 && $minLine >= 1 => 0.72,
            $maxLine <= 6 => 0.38,
            default => 0.16,
        };
    }

    protected function frameQuality(int $frameCount, int $middleCount): float
    {
        return match (true) {
            $frameCount >= 8 && $frameCount <= 11 => 1.00,
            $frameCount === 7 || $frameCount === 12 => 0.76,
            $frameCount === 6 || $frameCount === 13 => 0.42,
            default => 0.18,
        };
    }

    protected function clusterQuality(float $clusterStrength): float
    {
        return match (true) {
            $clusterStrength >= 11 => 1.00,
            $clusterStrength >= 9 => 0.88,
            $clusterStrength >= 7 => 0.66,
            $clusterStrength >= 5 => 0.42,
            default => 0.20,
        };
    }

    protected function quadrantQuality(array $quadrantDistribution): float
    {
        $max = max($quadrantDistribution);
        $min = min($quadrantDistribution);

        return match (true) {
            $max <= 5 && $min >= 2 => 1.00,
            $max <= 6 && $min >= 1 => 0.72,
            $max <= 7 => 0.38,
            default => 0.16,
        };
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

    protected function extractNumbers(LotofacilConcurso $concurso): array
    {
        $numbers = [];

        for ($i = 1; $i <= 15; $i++) {
            $numbers[] = (int) $concurso->{'bola' . $i};
        }

        return $this->normalizeNumbers($numbers);
    }

    protected function normalizeNumbers(array $numbers): array
    {
        $numbers = array_values(array_unique(array_map('intval', $numbers)));
        sort($numbers);

        return $numbers;
    }
}
