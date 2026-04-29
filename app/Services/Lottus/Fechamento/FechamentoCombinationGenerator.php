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

        if (empty($allCombinations)) {
            return [];
        }

        return $this->rankCombinationsForSurvival($allCombinations, $dezenasBase);
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

    protected function rankCombinationsForSurvival(array $combinations, array $dezenasBase): array
    {
        $ranked = [];

        foreach ($combinations as $combination) {
            $combination = array_values(array_unique(array_map('intval', $combination)));
            sort($combination);

            if (count($combination) !== 15) {
                continue;
            }

            $omitted = array_values(array_diff($dezenasBase, $combination));
            sort($omitted);

            $ranked[] = [
                'dezenas' => $combination,
                'omitted' => $omitted,
                'survival_score' => $this->survivalScore($combination, $omitted, $dezenasBase),
            ];
        }

        usort($ranked, function (array $a, array $b): int {
            if ($a['survival_score'] === $b['survival_score']) {
                return $this->candidateKey($a['omitted']) <=> $this->candidateKey($b['omitted']);
            }

            return $b['survival_score'] <=> $a['survival_score'];
        });

        return array_map(
            fn (array $item): array => $item['dezenas'],
            $ranked
        );
    }

    protected function survivalScore(array $combination, array $omitted, array $dezenasBase): float
    {
        $score = 0.0;

        $score += $this->omittedBalanceScore($omitted, $dezenasBase) * 4.0;
        $score += $this->gameStructureScore($combination) * 2.5;
        $score += $this->edgePreservationScore($combination) * 1.5;
        $score += $this->zoneCoverageScore($combination) * 1.5;
        $score += $this->omittedSpreadScore($omitted) * 1.2;

        return round($score, 8);
    }

    protected function omittedBalanceScore(array $omitted, array $dezenasBase): float
    {
        if (empty($omitted)) {
            return 0.0;
        }

        $low = 0;
        $middle = 0;
        $high = 0;

        foreach ($omitted as $number) {
            if ($number <= 8) {
                $low++;
            } elseif ($number <= 17) {
                $middle++;
            } else {
                $high++;
            }
        }

        $score = 0.0;

        if ($low >= 1) {
            $score += 0.30;
        }

        if ($middle >= 1) {
            $score += 0.35;
        }

        if ($high >= 1) {
            $score += 0.30;
        }

        if (count($omitted) >= 4) {
            $distinctZones = count(array_filter([$low, $middle, $high], fn ($value) => $value > 0));

            if ($distinctZones >= 3) {
                $score += 0.15;
            }
        }

        return min(1.0, $score);
    }

    protected function omittedSpreadScore(array $omitted): float
    {
        if (count($omitted) <= 1) {
            return 0.5;
        }

        sort($omitted);

        $distances = [];

        for ($i = 1; $i < count($omitted); $i++) {
            $distances[] = $omitted[$i] - $omitted[$i - 1];
        }

        $averageDistance = array_sum($distances) / count($distances);

        if ($averageDistance >= 5) {
            return 1.0;
        }

        if ($averageDistance >= 3) {
            return 0.75;
        }

        if ($averageDistance >= 2) {
            return 0.55;
        }

        return 0.25;
    }

    protected function gameStructureScore(array $game): float
    {
        $sum = array_sum($game);
        $oddCount = count(array_filter($game, fn ($number) => $number % 2 !== 0));
        $longestSequence = $this->longestSequence($game);
        $lines = $this->lineDistribution($game);
        $quadrants = $this->quadrantDistribution($game);

        $score = 0.0;

        if ($sum >= 155 && $sum <= 230) {
            $score += 0.22;
        }

        if ($oddCount >= 5 && $oddCount <= 10) {
            $score += 0.20;
        }

        if ($longestSequence >= 2 && $longestSequence <= 8) {
            $score += 0.18;
        }

        if (max($lines) <= 5 && min($lines) >= 1) {
            $score += 0.20;
        }

        if (max($quadrants) <= 6 && min($quadrants) >= 1) {
            $score += 0.20;
        }

        return min(1.0, $score);
    }

    protected function edgePreservationScore(array $game): float
    {
        $edges = [
            1, 2, 3, 4, 5,
            6, 10,
            11, 15,
            16, 20,
            21, 22, 23, 24, 25,
        ];

        $edgeCount = count(array_intersect($game, $edges));

        if ($edgeCount >= 8 && $edgeCount <= 12) {
            return 1.0;
        }

        if ($edgeCount >= 7 && $edgeCount <= 13) {
            return 0.7;
        }

        return 0.35;
    }

    protected function zoneCoverageScore(array $game): float
    {
        $zones = [
            [1, 2, 3, 4, 5],
            [6, 7, 8, 9, 10],
            [11, 12, 13, 14, 15],
            [16, 17, 18, 19, 20],
            [21, 22, 23, 24, 25],
        ];

        $score = 0.0;

        foreach ($zones as $zone) {
            $count = count(array_intersect($game, $zone));

            if ($count >= 2 && $count <= 4) {
                $score += 0.20;
            } elseif ($count >= 1 && $count <= 5) {
                $score += 0.10;
            }
        }

        return min(1.0, $score);
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

    protected function candidateKey(array $dezenas): string
    {
        $dezenas = array_values(array_unique(array_map('intval', $dezenas)));

        sort($dezenas);

        return implode('-', $dezenas);
    }
}