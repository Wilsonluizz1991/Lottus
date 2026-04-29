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

        $maxPoolSize = match ($quantidadeJogos) {
            90 => 1800,
            72 => 1500,
            54 => 1200,
            36 => 900,
            default => 800,
        };

        if (count($workingPool) > $maxPoolSize) {
            $workingPool = array_slice($workingPool, 0, $maxPoolSize);
        }

        $eliteSeedCount = min(
            max(4, (int) floor($quantidadeJogos * 0.14)),
            count($workingPool)
        );

        $eliteSeeds = array_slice($workingPool, 0, $eliteSeedCount);

        foreach ($eliteSeeds as $eliteCandidate) {
            $this->addCandidate(
                selected: $selected,
                seen: $seen,
                candidate: $eliteCandidate,
                coveredOmittedSingles: $coveredOmittedSingles,
                coveredOmittedPairs: $coveredOmittedPairs,
                coveredOmittedTriples: $coveredOmittedTriples,
                omittedFrequency: $omittedFrequency
            );
        }

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
            foreach ($workingPool as $candidate) {
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
        $omitted = array_values(array_unique(array_map('intval', $candidate['omitted_dezenas'] ?? [])));
        sort($omitted);

        $score = (float) ($candidate['normalized_score'] ?? 0.0);
        $eliteBonus = (float) ($candidate['elite_bonus'] ?? 0.0);

        $value = 0.0;

        $value += $score * 135.0;
        $value += min(35.0, $eliteBonus * 2.0);

        $value += $this->newOmittedSinglesValue($omitted, $coveredOmittedSingles);
        $value += $this->newOmittedPairsValue($omitted, $coveredOmittedPairs);
        $value += $this->newOmittedTriplesValue($omitted, $coveredOmittedTriples);

        $value += $this->omittedBalanceValue($omitted, $omittedFrequency);
        $value += $this->omittedDistanceValue($omitted, $selected) * 0.40;
        $value -= $this->omittedClonePenalty($omitted, $selected);
        $value += $this->elitePreservationBonus($candidate, $selected);

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

        $omitted = array_values(array_unique(
            array_map('intval', $candidate['omitted_dezenas'] ?? [])
        ));
        sort($omitted);

        $count = count($omitted);

        foreach ($omitted as $number) {
            $coveredOmittedSingles[$number] = true;
            $omittedFrequency[$number] = ($omittedFrequency[$number] ?? 0) + 1;
        }

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $coveredOmittedPairs[$omitted[$i] . '-' . $omitted[$j]] = true;
            }
        }

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                for ($k = $j + 1; $k < $count; $k++) {
                    $coveredOmittedTriples[$omitted[$i] . '-' . $omitted[$j] . '-' . $omitted[$k]] = true;
                }
            }
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
        $omitted = array_values(array_unique(array_map('intval', $omitted)));
        sort($omitted);

        $value = 0.0;
        $count = count($omitted);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $key = $omitted[$i] . '-' . $omitted[$j];

                if (! isset($coveredOmittedPairs[$key])) {
                    $value += 7.0;
                }
            }
        }

        return $value;
    }

    protected function newOmittedTriplesValue(array $omitted, array $coveredOmittedTriples): float
    {
        $omitted = array_values(array_unique(array_map('intval', $omitted)));
        sort($omitted);

        $value = 0.0;
        $count = count($omitted);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                for ($k = $j + 1; $k < $count; $k++) {
                    $key = $omitted[$i] . '-' . $omitted[$j] . '-' . $omitted[$k];

                    if (! isset($coveredOmittedTriples[$key])) {
                        $value += 4.5;
                    }
                }
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
            $selectedOmitted = array_values(array_unique(array_map(
                'intval',
                $selectedGame['omitted_dezenas'] ?? []
            )));
            sort($selectedOmitted);

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
                $penalty += 180.0;
                continue;
            }

            if ($omittedCount >= 3 && $overlap === 3) {
                $penalty += 4.5;
                continue;
            }

            if ($omittedCount >= 3 && $overlap === 2) {
                $penalty += 1.2;
                continue;
            }

            if ($overlap === 1) {
                $penalty += 0.15;
            }
        }

        return $penalty;
    }

    protected function elitePreservationBonus(array $candidate, array $selected): float
    {
        if (empty($selected)) {
            return 0.0;
        }

        $bonus = 0.0;

        $candidateScore = (float) ($candidate['normalized_score'] ?? 0.0);
        $eliteBonus = (float) ($candidate['elite_bonus'] ?? 0.0);

        if ($candidateScore >= 0.88) {
            $bonus += 12.0;
        }

        if ($candidateScore >= 0.93) {
            $bonus += 18.0;
        }

        if ($eliteBonus >= 4.0) {
            $bonus += 10.0;
        }

        if ($eliteBonus >= 7.0) {
            $bonus += 18.0;
        }

        return $bonus;
    }

    protected function candidateKey(array $dezenas): string
    {
        $dezenas = array_values(array_unique(array_map('intval', $dezenas)));

        sort($dezenas);

        return implode('-', $dezenas);
    }
}