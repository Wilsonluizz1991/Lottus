<?php

namespace App\Services\Lottus\Generation;

use App\Models\LotofacilConcurso;

class GameScoringService
{
    public function rank(
        array $candidates,
        array $frequencyContext,
        array $delayContext,
        array $correlationContext,
        array $structureContext,
        LotofacilConcurso $concursoBase
    ): array {
        $ultimoConcurso = $this->extractNumbers($concursoBase);
        $ranked = [];

        foreach ($candidates as $candidate) {
            $game = $candidate['dezenas'] ?? $candidate;
            $profile = $candidate['profile'] ?? 'unknown';
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
            $cycleHits = count(array_intersect($game, $cycleMissing));
            $sum = array_sum($game);
            $oddCount = count(array_filter($game, fn ($n) => $n % 2 !== 0));
            $evenCount = 15 - $oddCount;
            $longestSequence = $this->longestSequence($game);
            $clusterStrength = $this->clusterStrength($game);

            $score =
                ($frequencyScore * 0.42) +
                ($delayScore * 0.34) +
                ($correlationScore * 24.00) +
                ($repeatCount * 0.85) +
                ($cycleHits * 1.10) +
                ($clusterStrength * 6.00);

            if ($longestSequence >= 3) {
                $score += 0.35;
            }

            if ($longestSequence >= 4) {
                $score += 0.25;
            }

            if ($longestSequence >= 5) {
                $score += 0.15;
            }

            if ($sum >= 165 && $sum <= 220) {
                $score += 0.20;
            }

            if ($oddCount >= 5 && $oddCount <= 10) {
                $score += 0.15;
            }

            $extremeScore =
                ($frequencyScore * 0.38) +
                ($delayScore * 0.32) +
                ($correlationScore * 26.00) +
                ($repeatCount * 0.95) +
                ($cycleHits * 1.25) +
                ($clusterStrength * 7.00);

            $ranked[] = [
                'dezenas' => $game,
                'profile' => $profile,
                'score' => round($score, 6),
                'extreme_score' => round($extremeScore, 6),
                'repetidas_ultimo_concurso' => $repeatCount,
                'cycle_hits' => $cycleHits,
                'pares' => $evenCount,
                'impares' => $oddCount,
                'soma' => $sum,
                'analise' => [
                    'sequencia_maxima' => $longestSequence,
                    'cluster_strength' => round($clusterStrength, 6),
                ],
            ];
        }

        usort($ranked, function ($a, $b) {
            $scoreA = ((float) ($a['score'] ?? 0.0) * 0.72) + ((float) ($a['extreme_score'] ?? 0.0) * 0.28);
            $scoreB = ((float) ($b['score'] ?? 0.0) * 0.72) + ((float) ($b['extreme_score'] ?? 0.0) * 0.28);

            return $scoreB <=> $scoreA;
        });

        return $ranked;
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

        return $count > 0 ? ($total / $count) : 0.0;
    }

    protected function longestSequence(array $game): int
    {
        if (empty($game)) {
            return 0;
        }

        $current = 1;
        $longest = 1;

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

        return $clusters / 15;
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