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

        $pool = array_values($scoredCombinations);

        usort($pool, fn ($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        $selected = [];
        $seen = [];

        $eliteSeeds = $this->extractEliteSeeds($pool, $quantidadeJogos);

        foreach ($eliteSeeds as $candidate) {
            $this->tryAdd($selected, $seen, $candidate, $quantidadeJogos);
        }

        foreach ($pool as $candidate) {
            if (count($selected) >= $quantidadeJogos) {
                break;
            }

            if (! $this->isCoverageUseful($candidate, $selected, $dezenasBase)) {
                continue;
            }

            $this->tryAdd($selected, $seen, $candidate, $quantidadeJogos);
        }

        if (count($selected) < $quantidadeJogos) {
            foreach ($pool as $candidate) {
                if (count($selected) >= $quantidadeJogos) {
                    break;
                }

                $this->tryAdd($selected, $seen, $candidate, $quantidadeJogos);
            }
        }

        usort($selected, fn ($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        return array_slice($selected, 0, $quantidadeJogos);
    }

    protected function extractEliteSeeds(array $pool, int $quantidadeJogos): array
    {
        $limit = min(
            max(5, (int) ceil($quantidadeJogos * 0.18)),
            count($pool)
        );

        return array_slice($pool, 0, $limit);
    }

    protected function tryAdd(
        array &$selected,
        array &$seen,
        array $candidate,
        int $quantidadeJogos
    ): bool {
        if (count($selected) >= $quantidadeJogos) {
            return false;
        }

        $key = $this->candidateKey($candidate['dezenas'] ?? []);

        if (isset($seen[$key])) {
            return false;
        }

        $selected[] = $candidate;
        $seen[$key] = true;

        return true;
    }

    protected function isCoverageUseful(
        array $candidate,
        array $selected,
        array $dezenasBase
    ): bool {
        if (empty($selected)) {
            return true;
        }

        $game = $candidate['dezenas'] ?? [];

        if (count($game) !== 15) {
            return false;
        }

        $minOverlap = (int) config('lottus_fechamento.reducer.min_overlap_between_games', 9);
        $maxOverlap = (int) config('lottus_fechamento.reducer.max_overlap_between_games', 14);
        $eliteOverlapBonusMin = (int) config('lottus_fechamento.reducer.elite_overlap_bonus_min', 11);

        $coverageGain = $this->coverageGain($game, $selected, $dezenasBase);
        $bestOverlap = $this->bestOverlap($game, $selected);
        $averageOverlap = $this->averageOverlap($game, $selected);

        if ($bestOverlap >= 15) {
            return false;
        }

        if ($bestOverlap > $maxOverlap && $coverageGain < 2) {
            return false;
        }

        if ($averageOverlap < $minOverlap && ($candidate['score'] ?? 0) < $this->averageSelectedScore($selected)) {
            return false;
        }

        if ($coverageGain >= 2) {
            return true;
        }

        if ($bestOverlap >= $eliteOverlapBonusMin && ($candidate['elite_bonus'] ?? 0) > 0) {
            return true;
        }

        return ($candidate['score'] ?? 0) >= $this->averageSelectedScore($selected);
    }

    protected function coverageGain(array $game, array $selected, array $dezenasBase): int
    {
        $covered = [];

        foreach ($selected as $selectedGame) {
            foreach (($selectedGame['dezenas'] ?? []) as $number) {
                $covered[(int) $number] = true;
            }
        }

        $gain = 0;

        foreach ($game as $number) {
            $number = (int) $number;

            if (! in_array($number, $dezenasBase, true)) {
                continue;
            }

            if (! isset($covered[$number])) {
                $gain++;
            }
        }

        return $gain;
    }

    protected function bestOverlap(array $game, array $selected): int
    {
        $best = 0;

        foreach ($selected as $selectedGame) {
            $overlap = count(array_intersect($game, $selectedGame['dezenas'] ?? []));
            $best = max($best, $overlap);
        }

        return $best;
    }

    protected function averageOverlap(array $game, array $selected): float
    {
        if (empty($selected)) {
            return 0.0;
        }

        $total = 0;

        foreach ($selected as $selectedGame) {
            $total += count(array_intersect($game, $selectedGame['dezenas'] ?? []));
        }

        return $total / count($selected);
    }

    protected function averageSelectedScore(array $selected): float
    {
        if (empty($selected)) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($selected as $selectedGame) {
            $total += (float) ($selectedGame['score'] ?? 0.0);
        }

        return $total / count($selected);
    }

    protected function candidateKey(array $dezenas): string
    {
        $dezenas = array_values(array_unique(array_map('intval', $dezenas)));

        sort($dezenas);

        return implode('-', $dezenas);
    }
}