<?php

namespace App\Services\Lottus\Analysis;

use Illuminate\Support\Collection;

class FrequencyAnalysisService
{
    public function analyze(Collection $historico): array
    {
        $windows = [10, 20, 50, 100];
        $result = [
            'global' => $this->calculateWindowFrequency($historico),
            'windows' => [],
            'scores' => [],
        ];

        foreach ($windows as $window) {
            $slice = $historico->take(-1 * min($window, $historico->count()));
            $result['windows'][$window] = $this->calculateWindowFrequency($slice);
        }

        foreach (range(1, 25) as $dezena) {
            $global = $result['global'][$dezena] ?? 0;
            $f10 = $result['windows'][10][$dezena] ?? $global;
            $f20 = $result['windows'][20][$dezena] ?? $global;
            $f50 = $result['windows'][50][$dezena] ?? $global;
            $f100 = $result['windows'][100][$dezena] ?? $global;

            $result['scores'][$dezena] = (
                ($f10 * 0.35) +
                ($f20 * 0.25) +
                ($f50 * 0.20) +
                ($f100 * 0.10) +
                ($global * 0.10)
            );
        }

        return $result;
    }

    protected function calculateWindowFrequency(Collection $historico): array
    {
        $counts = array_fill(1, 25, 0);
        $total = max($historico->count(), 1);

        foreach ($historico as $concurso) {
            foreach ($concurso['dezenas'] as $dezena) {
                $counts[$dezena]++;
            }
        }

        foreach ($counts as $dezena => $count) {
            $counts[$dezena] = $count / $total;
        }

        return $counts;
    }
}