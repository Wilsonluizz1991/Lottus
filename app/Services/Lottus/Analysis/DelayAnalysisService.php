<?php

namespace App\Services\Lottus\Analysis;

use Illuminate\Support\Collection;

class DelayAnalysisService
{
    public function analyze(Collection $historico): array
    {
        $ultimoIndice = [];
        $scores = [];
        $currentContestIndex = $historico->count() - 1;

        foreach (range(1, 25) as $dezena) {
            $ultimoIndice[$dezena] = null;
        }

        foreach ($historico as $index => $concurso) {
            foreach ($concurso['dezenas'] as $dezena) {
                $ultimoIndice[$dezena] = $index;
            }
        }

        foreach (range(1, 25) as $dezena) {
            if ($ultimoIndice[$dezena] === null) {
                $delay = $historico->count();
            } else {
                $delay = $currentContestIndex - $ultimoIndice[$dezena];
            }

            $scores[$dezena] = 1 + ($delay / max($historico->count(), 1));
        }

        return [
            'delays' => $ultimoIndice,
            'scores' => $scores,
        ];
    }
}