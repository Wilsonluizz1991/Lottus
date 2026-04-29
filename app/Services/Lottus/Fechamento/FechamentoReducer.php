<?php

namespace App\Services\Lottus\Fechamento;

class FechamentoReducer
{
    public function reduce(
        array $scoredCombinations,
        int $quantidadeJogos,
        array $dezenasBase
    ): array {
        if ($quantidadeJogos <= 0 || empty($scoredCombinations)) {
            return [];
        }

        $dezenasBase = array_values(array_unique(array_map('intval', $dezenasBase)));
        sort($dezenasBase);

        $pool = $this->normalizePool($scoredCombinations, $dezenasBase);

        if (empty($pool)) {
            return [];
        }

        usort($pool, fn ($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        $pool = $this->prepareNormalizedScore($pool);

        $selected = [];
        $seen = [];
        $coveredOmittedSingles = [];
        $coveredOmittedPairs = [];
        $coveredOmittedTriples = [];
        $omittedFrequency = [];

        $candidateWindow = min(
            count($pool),
            max(
                $quantidadeJogos * 120,
                (int) config('lottus_fechamento.reducer.candidate_window', 2500)
            )
        );

        $workingPool = array_slice($pool, 0, $candidateWindow);

        $firstCandidate = $workingPool[0];

        $this->addCandidate(
            selected: $selected,
            seen: $seen,
            candidate: $firstCandidate,
            coveredOmittedSingles: $coveredOmittedSingles,
            coveredOmittedPairs: $coveredOmittedPairs,
            coveredOmittedTriples: $coveredOmittedTriples,
            omittedFrequency: $omittedFrequency
        );

        while (count($selected) < $quantidadeJogos && count($selected) < count($workingPool)) {
            $bestIndex = null;
            $bestValue = null;

            foreach ($workingPool as $index => $candidate) {
                $key = $this->candidateKey($candidate['dezenas'] ?? []);

                if (isset($seen[$key])) {
                    continue;
                }

                $value = $this->portfolioValue(
                    candidate: $candidate,
                    selected: $selected,
                    coveredOmittedSingles: $coveredOmittedSingles,
                    coveredOmittedPairs: $coveredOmittedPairs,
                    coveredOmittedTriples: $coveredOmittedTriples,
                    omittedFrequency: $omittedFrequency
                );

                if ($bestValue === null || $value > $bestValue) {
                    $bestValue = $value;
                    $bestIndex = $index;
                }
            }

            if ($bestIndex === null) {
                break;
            }

            $this->addCandidate(
                selected: $selected,
                seen: $seen,
                candidate: $workingPool[$bestIndex],
                coveredOmittedSingles: $coveredOmittedSingles,
                coveredOmittedPairs: $coveredOmittedPairs,
                coveredOmittedTriples: $coveredOmittedTriples,
                omittedFrequency: $omittedFrequency
            );
        }

        if (count($selected) < $quantidadeJogos) {
            foreach ($pool as $candidate) {
                if (count($selected) >= $quantidadeJogos) {
                    break;
                }

                $this->addCandidate(
                    selected: $selected,
                    seen: $seen,
                    candidate: $candidate,
                    coveredOmittedSingles: $coveredOmittedSingles,
                    coveredOmittedPairs: $coveredOmittedPairs,
                    coveredOmittedTriples: $coveredOmittedTriples,
                    omittedFrequency: $omittedFrequency
                );
            }
        }

        foreach ($selected as $index => &$candidate) {
            $candidate['portfolio_order'] = $index + 1;
        }

        unset($candidate);

        return array_slice($selected, 0, $quantidadeJogos);
    }

    protected function normalizePool(array $scoredCombinations, array $dezenasBase): array
    {
        $pool = [];
        $seen = [];

        foreach ($scoredCombinations as $candidate) {
            $game = $candidate['dezenas'] ?? $candidate;

            $game = array_values(array_unique(array_map('intval', $game)));
            sort($game);

            if (count($game) !== 15) {
                continue;
            }

            $key = $this->candidateKey($game);

            if (isset($seen[$key])) {
                continue;
            }

            $omitted = array_values(array_diff($dezenasBase, $game));
            sort($omitted);

            if (count($omitted) !== count($dezenasBase) - 15) {
                continue;
            }

            $candidate['dezenas'] = $game;
            $candidate['omitted_dezenas'] = $omitted;
            $candidate['omitted_key'] = $this->candidateKey($omitted);

            $pool[] = $candidate;
            $seen[$key] = true;
        }

        return $pool;
    }

    protected function prepareNormalizedScore(array $pool): array
    {
        $scores = array_map(
            fn ($candidate) => (float) ($candidate['score'] ?? 0.0),
            $pool
        );

        $min = min($scores);
        $max = max($scores);

        foreach ($pool as &$candidate) {
            $score = (float) ($candidate['score'] ?? 0.0);

            if ($max <= $min) {
                $candidate['normalized_score'] = 1.0;
            } else {
                $candidate['normalized_score'] = ($score - $min) / ($max - $min);
            }
        }

        unset($candidate);

        return $pool;
    }

    protected function portfolioValue(
        array $candidate,
        array $selected,
        array $coveredOmittedSingles,
        array $coveredOmittedPairs,
        array $coveredOmittedTriples,
        array $omittedFrequency
    ): float {
        $omitted = $candidate['omitted_dezenas'] ?? [];
        $score = (float) ($candidate['normalized_score'] ?? 0.0);
        $eliteBonus = (float) ($candidate['elite_bonus'] ?? 0.0);

        $value = 0.0;

        $value += $score * 100.0;
        $value += min(20.0, $eliteBonus * 1.25);

        $value += $this->newOmittedSinglesValue($omitted, $coveredOmittedSingles);
        $value += $this->newOmittedPairsValue($omitted, $coveredOmittedPairs);
        $value += $this->newOmittedTriplesValue($omitted, $coveredOmittedTriples);

        $value += $this->omittedBalanceValue($omitted, $omittedFrequency);
        $value += $this->omittedDistanceValue($omitted, $selected);
        $value -= $this->omittedClonePenalty($omitted, $selected);

        return $value;
    }

    protected function addCandidate(
        array &$selected,
        array &$seen,
        array $candidate,
        array &$coveredOmittedSingles,
        array &$coveredOmittedPairs,
        array &$coveredOmittedTriples,
        array &$omittedFrequency
    ): bool {
        $key = $this->candidateKey($candidate['dezenas'] ?? []);

        if (isset($seen[$key])) {
            return false;
        }

        $selected[] = $candidate;
        $seen[$key] = true;

        $omitted = $candidate['omitted_dezenas'] ?? [];

        foreach ($omitted as $number) {
            $number = (int) $number;

            $coveredOmittedSingles[$number] = true;
            $omittedFrequency[$number] = ($omittedFrequency[$number] ?? 0) + 1;
        }

        foreach ($this->subsets($omitted, 2) as $pair) {
            $coveredOmittedPairs[$this->candidateKey($pair)] = true;
        }

        foreach ($this->subsets($omitted, 3) as $triple) {
            $coveredOmittedTriples[$this->candidateKey($triple)] = true;
        }

        return true;
    }

    protected function newOmittedSinglesValue(array $omitted, array $coveredOmittedSingles): float
    {
        $value = 0.0;

        foreach ($omitted as $number) {
            if (! isset($coveredOmittedSingles[(int) $number])) {
                $value += 16.0;
            }
        }

        return $value;
    }

    protected function newOmittedPairsValue(array $omitted, array $coveredOmittedPairs): float
    {
        $value = 0.0;

        foreach ($this->subsets($omitted, 2) as $pair) {
            if (! isset($coveredOmittedPairs[$this->candidateKey($pair)])) {
                $value += 7.0;
            }
        }

        return $value;
    }

    protected function newOmittedTriplesValue(array $omitted, array $coveredOmittedTriples): float
    {
        $value = 0.0;

        foreach ($this->subsets($omitted, 3) as $triple) {
            if (! isset($coveredOmittedTriples[$this->candidateKey($triple)])) {
                $value += 4.5;
            }
        }

        return $value;
    }

    protected function omittedBalanceValue(array $omitted, array $omittedFrequency): float
    {
        if (empty($omitted)) {
            return 0.0;
        }

        $value = 0.0;

        foreach ($omitted as $number) {
            $frequency = (int) ($omittedFrequency[(int) $number] ?? 0);

            if ($frequency === 0) {
                $value += 10.0;
            } elseif ($frequency <= 2) {
                $value += 5.0;
            } elseif ($frequency <= 5) {
                $value += 2.0;
            } else {
                $value -= min(12.0, ($frequency - 5) * 1.5);
            }
        }

        return $value;
    }

    protected function omittedDistanceValue(array $omitted, array $selected): float
    {
        if (empty($selected)) {
            return 0.0;
        }

        $totalDistance = 0.0;
        $comparisons = 0;

        foreach ($selected as $selectedGame) {
            $selectedOmitted = $selectedGame['omitted_dezenas'] ?? [];

            $intersection = count(array_intersect($omitted, $selectedOmitted));
            $union = count(array_unique(array_merge($omitted, $selectedOmitted)));

            if ($union === 0) {
                continue;
            }

            $totalDistance += 1.0 - ($intersection / $union);
            $comparisons++;
        }

        if ($comparisons === 0) {
            return 0.0;
        }

        return ($totalDistance / $comparisons) * 22.0;
    }

    protected function omittedClonePenalty(array $omitted, array $selected): float
    {
        if (empty($selected)) {
            return 0.0;
        }

        $penalty = 0.0;
        $omittedCount = count($omitted);

        foreach ($selected as $selectedGame) {
            $selectedOmitted = $selectedGame['omitted_dezenas'] ?? [];
            $overlap = count(array_intersect($omitted, $selectedOmitted));

            if ($overlap === $omittedCount && $omittedCount > 0) {
                $penalty += 999.0;
                continue;
            }

            if ($omittedCount >= 3 && $overlap >= $omittedCount - 1) {
                $penalty += 18.0;
                continue;
            }

            if ($omittedCount >= 2 && $overlap >= $omittedCount - 1) {
                $penalty += 8.0;
            }
        }

        return $penalty;
    }

    protected function subsets(array $numbers, int $size): array
    {
        $numbers = array_values(array_unique(array_map('intval', $numbers)));
        sort($numbers);

        if ($size <= 0 || count($numbers) < $size) {
            return [];
        }

        $result = [];

        $this->buildSubsets(
            source: $numbers,
            size: $size,
            start: 0,
            current: [],
            result: $result
        );

        return $result;
    }

    protected function buildSubsets(
        array $source,
        int $size,
        int $start,
        array $current,
        array &$result
    ): void {
        if (count($current) === $size) {
            $result[] = $current;

            return;
        }

        $remainingNeeded = $size - count($current);

        for ($i = $start; $i <= count($source) - $remainingNeeded; $i++) {
            $current[] = $source[$i];

            $this->buildSubsets(
                source: $source,
                size: $size,
                start: $i + 1,
                current: $current,
                result: $result
            );

            array_pop($current);
        }
    }

    protected function candidateKey(array $dezenas): string
    {
        $dezenas = array_values(array_unique(array_map('intval', $dezenas)));

        sort($dezenas);

        return implode('-', $dezenas);
    }
}