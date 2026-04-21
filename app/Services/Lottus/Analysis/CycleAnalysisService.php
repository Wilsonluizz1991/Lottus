<?php

namespace App\Services\Lottus\Analysis;

use Illuminate\Support\Collection;

class CycleAnalysisService
{
    public function analyze(Collection $historico): array
    {
        $ultimoCiclo = $this->getCurrentCycle($historico);

        $faltantes = array_values(array_diff(range(1, 25), $ultimoCiclo));

        $scores = [];

        foreach (range(1, 25) as $dezena) {
            if (in_array($dezena, $faltantes)) {
                $scores[$dezena] = 2.0;
            } else {
                $scores[$dezena] = 1.0;
            }
        }

        return [
            'numeros_ciclo' => $ultimoCiclo,
            'faltantes' => $faltantes,
            'scores' => $scores,
        ];
    }

    protected function getCurrentCycle(Collection $historico): array
    {
        $seen = [];

        for ($i = $historico->count() - 1; $i >= 0; $i--) {
            $concurso = $historico[$i];

            foreach ($concurso['dezenas'] as $dezena) {
                $seen[$dezena] = true;
            }

            if (count($seen) >= 25) {
                break;
            }
        }

        return array_keys($seen);
    }
}