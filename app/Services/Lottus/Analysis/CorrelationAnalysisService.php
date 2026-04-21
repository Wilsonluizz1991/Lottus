<?php

namespace App\Services\Lottus\Analysis;

use Illuminate\Support\Collection;

class CorrelationAnalysisService
{
    public function analyze(Collection $historico): array
    {
        $pairScores = [];

        foreach (range(1, 25) as $a) {
            foreach (range(1, 25) as $b) {
                $pairScores[$a][$b] = 0.0;
            }
        }

        foreach ($historico as $concurso) {
            $dezenas = $concurso['dezenas'];
            $total = count($dezenas);

            for ($i = 0; $i < $total; $i++) {
                for ($j = $i + 1; $j < $total; $j++) {
                    $a = $dezenas[$i];
                    $b = $dezenas[$j];
                    $pairScores[$a][$b] += 1;
                    $pairScores[$b][$a] += 1;
                }
            }
        }

        $normalizador = max($historico->count(), 1);

        foreach (range(1, 25) as $a) {
            foreach (range(1, 25) as $b) {
                $pairScores[$a][$b] = $pairScores[$a][$b] / $normalizador;
            }
        }

        return [
            'pair_scores' => $pairScores,
        ];
    }
}