<?php

namespace App\Services\Lottus\Generation;

class PortfolioOptimizerService
{
    public function optimize(array $rankedGames, int $quantidade): array
    {
        if ($quantidade <= 1) {
            return array_slice($this->sortForExplosiveness($rankedGames), 0, 1);
        }

        $rankedGames = $this->sortForExplosiveness($rankedGames);

        $selected = [];

        $aggressiveTargets = $this->aggressiveTargetCount($quantidade);

        $selected = array_merge(
            $selected,
            $this->takeByProfiles($rankedGames, ['aggressive', 'delay_biased'], $selected, $aggressiveTargets)
        );

        while (count($selected) < $quantidade) {
            $candidate = $this->pickBestExplosiveCandidate($rankedGames, $selected);

            if ($candidate === null) {
                break;
            }

            $selected[] = $candidate;
        }

        if (count($selected) < $quantidade) {
            foreach ($rankedGames as $candidate) {
                if (count($selected) >= $quantidade) {
                    break;
                }

                if ($this->alreadySelected($candidate, $selected)) {
                    continue;
                }

                $selected[] = $candidate;
            }
        }

        return array_slice($selected, 0, $quantidade);
    }

    protected function aggressiveTargetCount(int $quantidade): int
    {
        return match (true) {
            $quantidade <= 2 => 1,
            $quantidade <= 4 => 2,
            $quantidade <= 6 => 3,
            default => 4,
        };
    }

    protected function takeByProfiles(array $rankedGames, array $profiles, array $selected, int $limit): array
    {
        $result = [];

        foreach ($rankedGames as $candidate) {
            if (count($result) >= $limit) {
                break;
            }

            if (! in_array($candidate['profile'] ?? 'balanced', $profiles, true)) {
                continue;
            }

            if ($this->alreadySelected($candidate, array_merge($selected, $result))) {
                continue;
            }

            if ($this->isTooSimilarForExplosivePortfolio($candidate, array_merge($selected, $result))) {
                continue;
            }

            $result[] = $candidate;
        }

        return $result;
    }

    protected function pickBestExplosiveCandidate(array $rankedGames, array $selected): ?array
    {
        foreach ($rankedGames as $candidate) {
            if ($this->alreadySelected($candidate, $selected)) {
                continue;
            }

            if ($this->isTooSimilarForExplosivePortfolio($candidate, $selected)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    protected function sortForExplosiveness(array $rankedGames): array
    {
        usort($rankedGames, function ($a, $b) {
            $scoreA = $this->explosiveScore($a);
            $scoreB = $this->explosiveScore($b);

            return $scoreB <=> $scoreA;
        });

        return $rankedGames;
    }

    protected function explosiveScore(array $candidate): float
    {
        $baseScore = (float) ($candidate['score'] ?? 0.0);
        $profile = $candidate['profile'] ?? 'balanced';
        $repeatCount = (int) ($candidate['repetidas_ultimo_concurso'] ?? 0);
        $cycleHits = (int) ($candidate['cycle_hits'] ?? 0);

        $profileBoost = match ($profile) {
            'aggressive' => 1.35,
            'delay_biased' => 1.22,
            'correlation_biased' => 1.10,
            default => 1.00,
        };

        $repeatBoost = match (true) {
            $repeatCount >= 9 && $repeatCount <= 10 => 1.25,
            $repeatCount === 11 => 1.15,
            $repeatCount === 8 => 1.05,
            default => 0.90,
        };

        $cycleBoost = match (true) {
            $cycleHits >= 4 => 1.25,
            $cycleHits === 3 => 1.15,
            $cycleHits === 2 => 1.05,
            default => 0.90,
        };

        return $baseScore * $profileBoost * $repeatBoost * $cycleBoost;
    }

    protected function isTooSimilarForExplosivePortfolio(array $candidate, array $selected): bool
    {
        foreach ($selected as $game) {
            $intersection = count(array_intersect($candidate['dezenas'], $game['dezenas']));

            if ($intersection >= 12) {
                return true;
            }
        }

        return false;
    }

    protected function alreadySelected(array $candidate, array $selected): bool
    {
        $candidateKey = implode('-', $candidate['dezenas']);

        foreach ($selected as $game) {
            $selectedKey = implode('-', $game['dezenas']);

            if ($candidateKey === $selectedKey) {
                return true;
            }
        }

        return false;
    }
}