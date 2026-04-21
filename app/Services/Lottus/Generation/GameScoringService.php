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
            $profile = $candidate['profile'] ?? 'balanced';
            $cycleMissing = $candidate['cycle_missing'] ?? [];

            $frequencyScore = $this->sumMetric($game, $frequencyContext['scores']);
            $delayScore = $this->sumMetric($game, $delayContext['scores']);
            $correlationScore = $this->pairMetric($game, $correlationContext['pair_scores']);
            $repeatCount = count(array_intersect($game, $ultimoConcurso));
            $cycleHit = count(array_intersect($game, $cycleMissing));

            $score = (
                ($frequencyScore * 0.25) +
                ($delayScore * 0.15) +
                ($correlationScore * 0.20)
            );

            // 🔥 BOOST DE REPETIÇÃO (ALTÍSSIMO IMPACTO)
            if ($repeatCount >= 9 && $repeatCount <= 11) {
                $score *= 1.35;
            }

            // 🔥 BOOST DE CICLO (ALTÍSSIMO IMPACTO)
            if ($cycleHit >= 3) {
                $score *= 1.25;
            }

            // 🔥 BOOST DE PERFIL AGRESSIVO
            if ($profile === 'aggressive') {
                $score *= 1.20;
            }

            $ranked[] = [
                'dezenas' => $game,
                'profile' => $profile,
                'score' => round($score, 6),
                'pares' => count(array_filter($game, fn ($n) => $n % 2 === 0)),
                'impares' => count(array_filter($game, fn ($n) => $n % 2 !== 0)),
                'soma' => array_sum($game),
                'repetidas_ultimo_concurso' => $repeatCount,
                'cycle_hits' => $cycleHit,
                'analise' => [
                    'profile' => $profile,
                    'repeat' => $repeatCount,
                    'cycle_hits' => $cycleHit,
                ],
            ];
        }

        usort($ranked, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $ranked;
    }

    protected function sumMetric(array $game, array $scores): float
    {
        $total = 0;

        foreach ($game as $number) {
            $total += $scores[$number] ?? 0;
        }

        return $total;
    }

    protected function pairMetric(array $game, array $pairScores): float
    {
        $total = 0;
        $count = 0;

        for ($i = 0; $i < count($game); $i++) {
            for ($j = $i + 1; $j < count($game); $j++) {
                $total += $pairScores[$game[$i]][$game[$j]] ?? 0;
                $count++;
            }
        }

        return $count ? $total / $count : 0;
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