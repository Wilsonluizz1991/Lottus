<?php

namespace App\Services\Lottus\Fechamento;

class FechamentoCombinationGenerator
{
    public function generate(array $dezenasBase, int $quantidadeDezenas): array
    {
        $dezenasBase = array_values(array_unique(array_map('intval', $dezenasBase)));
        sort($dezenasBase);

        if ($quantidadeDezenas < 16 || $quantidadeDezenas > 20) {
            throw new \InvalidArgumentException('A quantidade de dezenas do fechamento deve estar entre 16 e 20.');
        }

        if (count($dezenasBase) !== $quantidadeDezenas) {
            throw new \InvalidArgumentException('A quantidade de dezenas base não corresponde ao fechamento solicitado.');
        }

        $maxInternalCombinations = (int) config('lottus_fechamento.reducer.max_internal_combinations', 16000);

        $allCombinations = [];
        $this->combine(
            source: $dezenasBase,
            choose: 15,
            start: 0,
            current: [],
            result: $allCombinations,
            limit: $maxInternalCombinations
        );

        return $allCombinations;
    }

    protected function combine(
        array $source,
        int $choose,
        int $start,
        array $current,
        array &$result,
        int $limit
    ): void {
        if (count($result) >= $limit) {
            return;
        }

        if (count($current) === $choose) {
            $combination = array_values($current);
            sort($combination);

            $result[] = $combination;

            return;
        }

        $remainingNeeded = $choose - count($current);
        $remainingAvailable = count($source) - $start;

        if ($remainingAvailable < $remainingNeeded) {
            return;
        }

        for ($i = $start; $i <= count($source) - $remainingNeeded; $i++) {
            $current[] = $source[$i];

            $this->combine(
                source: $source,
                choose: $choose,
                start: $i + 1,
                current: $current,
                result: $result,
                limit: $limit
            );

            array_pop($current);

            if (count($result) >= $limit) {
                return;
            }
        }
    }
}