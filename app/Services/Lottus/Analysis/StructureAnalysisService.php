<?php

namespace App\Services\Lottus\Analysis;

use Illuminate\Support\Collection;

class StructureAnalysisService
{
    public function analyze(Collection $historico): array
    {
        $sums = [];
        $oddCounts = [];
        $repeatCounts = [];

        $anterior = null;

        foreach ($historico as $concurso) {
            $dezenas = $concurso['dezenas'];

            $sums[] = array_sum($dezenas);
            $oddCounts[] = count(array_filter($dezenas, fn ($n) => $n % 2 !== 0));

            if ($anterior !== null) {
                $repeatCounts[] = count(array_intersect($dezenas, $anterior));
            }

            $anterior = $dezenas;
        }

        return [
            'sum_min' => $this->percentile($sums, 0.10),
            'sum_max' => $this->percentile($sums, 0.90),
            'odd_min' => $this->percentile($oddCounts, 0.10),
            'odd_max' => $this->percentile($oddCounts, 0.90),
            'repeat_min' => empty($repeatCounts) ? 6 : $this->percentile($repeatCounts, 0.10),
            'repeat_max' => empty($repeatCounts) ? 10 : $this->percentile($repeatCounts, 0.90),
        ];
    }

    protected function percentile(array $values, float $percent): int
    {
        sort($values);

        if (empty($values)) {
            return 0;
        }

        $index = (int) floor((count($values) - 1) * $percent);

        return (int) $values[$index];
    }
}