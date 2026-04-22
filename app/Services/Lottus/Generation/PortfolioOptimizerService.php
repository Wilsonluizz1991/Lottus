<?php

namespace App\Services\Lottus\Generation;

class PortfolioOptimizerService
{
    public function optimize(array $rankedGames, int $quantidade): array
    {
        if ($quantidade <= 0 || empty($rankedGames)) {
            return [];
        }

        $pool = array_values($rankedGames);

        usort($pool, function ($a, $b) {
            $valueA = $this->selectionValue($a);
            $valueB = $this->selectionValue($b);

            return $valueB <=> $valueA;
        });

        $selected = [];
        $seen = [];

        foreach ($pool as $candidate) {
            if (count($selected) >= $quantidade) {
                break;
            }

            $key = $this->candidateKey($candidate);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $selected[] = $candidate;
        }

        return array_slice($selected, 0, $quantidade);
    }

    protected function selectionValue(array $candidate): float
    {
        return
            ((float) ($candidate['score'] ?? 0.0) * 0.70) +
            ((float) ($candidate['extreme_score'] ?? 0.0) * 0.30);
    }

    protected function candidateKey(array $candidate): string
    {
        return implode('-', $candidate['dezenas'] ?? []);
    }
}