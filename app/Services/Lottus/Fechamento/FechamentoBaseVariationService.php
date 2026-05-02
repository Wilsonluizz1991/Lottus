<?php

namespace App\Services\Lottus\Fechamento;

class FechamentoBaseVariationService
{
    public function generate(
        array $base,
        array $numberScores,
        int $quantidadeDezenas,
        int $maxVariations = 3
    ): array {
        $base = array_values(array_unique(array_map('intval', $base)));
        sort($base);

        if ($quantidadeDezenas < 16 || $quantidadeDezenas > 20) {
            return [$base];
        }

        if (count($base) !== $quantidadeDezenas) {
            return [$base];
        }

        $scores = $this->normalizeNumberScores($numberScores);
        $strategy = $this->strategyFor($quantidadeDezenas);

        $core = $this->extractCore(
            base: $base,
            scores: $scores,
            coreSize: $strategy['core_size']
        );

        $removable = array_values(array_diff($base, $core));
        $outside = array_values(array_diff(range(1, 25), $base));

        usort($removable, fn ($a, $b) => ($scores[$a] ?? 0.0) <=> ($scores[$b] ?? 0.0));
        usort($outside, fn ($a, $b) => ($scores[$b] ?? 0.0) <=> ($scores[$a] ?? 0.0));

        $variations = [];
        $variations[] = $base;

        for ($variationIndex = 1; $variationIndex <= $maxVariations; $variationIndex++) {
            $swapCount = min(
                $variationIndex,
                $strategy['max_swaps'],
                count($removable),
                count($outside)
            );

            if ($swapCount <= 0) {
                continue;
            }

            $variation = $base;
            $usedIn = [];
            $usedOut = [];

            for ($i = 0; $i < $swapCount; $i++) {
                $remove = $this->pickRemovable(
                    removable: $removable,
                    scores: $scores,
                    used: $usedOut,
                    offset: $variationIndex + $i
                );

                $insert = $this->pickReplacement(
                    outside: $outside,
                    scores: $scores,
                    removeScore: $scores[$remove] ?? 0.0,
                    used: $usedIn,
                    maxScoreGap: $strategy['max_score_gap'],
                    offset: $variationIndex + $i
                );

                if ($remove === null || $insert === null) {
                    continue;
                }

                $variation = array_values(array_diff($variation, [$remove]));
                $variation[] = $insert;

                $usedOut[] = $remove;
                $usedIn[] = $insert;
            }

            $variation = array_values(array_unique(array_map('intval', $variation)));
            sort($variation);

            if (count($variation) !== $quantidadeDezenas) {
                continue;
            }

            if (! $this->isValidVariation($variation, $core, $strategy['minimum_core_overlap'])) {
                continue;
            }

            if (! $this->alreadyExists($variation, $variations)) {
                $variations[] = $variation;
            }
        }

        return $variations;
    }

    protected function strategyFor(int $quantidadeDezenas): array
    {
        return match ($quantidadeDezenas) {
            16 => [
                'core_size' => 15,
                'max_swaps' => 1,
                'minimum_core_overlap' => 15,
                'max_score_gap' => 0.08,
            ],
            17 => [
                'core_size' => 15,
                'max_swaps' => 2,
                'minimum_core_overlap' => 15,
                'max_score_gap' => 0.10,
            ],
            18 => [
                'core_size' => 14,
                'max_swaps' => 3,
                'minimum_core_overlap' => 14,
                'max_score_gap' => 0.12,
            ],
            19 => [
                'core_size' => 14,
                'max_swaps' => 4,
                'minimum_core_overlap' => 14,
                'max_score_gap' => 0.14,
            ],
            20 => [
                'core_size' => 13,
                'max_swaps' => 5,
                'minimum_core_overlap' => 13,
                'max_score_gap' => 0.16,
            ],
            default => [
                'core_size' => max(12, $quantidadeDezenas - 3),
                'max_swaps' => 2,
                'minimum_core_overlap' => max(12, $quantidadeDezenas - 4),
                'max_score_gap' => 0.10,
            ],
        };
    }

    protected function extractCore(array $base, array $scores, int $coreSize): array
    {
        $ranked = $base;

        usort($ranked, fn ($a, $b) => ($scores[$b] ?? 0.0) <=> ($scores[$a] ?? 0.0));

        $core = array_slice($ranked, 0, $coreSize);

        sort($core);

        return $core;
    }

    protected function pickRemovable(
        array $removable,
        array $scores,
        array $used,
        int $offset
    ): ?int {
        $available = array_values(array_diff($removable, $used));

        if (empty($available)) {
            return null;
        }

        usort($available, fn ($a, $b) => ($scores[$a] ?? 0.0) <=> ($scores[$b] ?? 0.0));

        $index = min(count($available) - 1, $offset % max(1, count($available)));

        return (int) $available[$index];
    }

    protected function pickReplacement(
        array $outside,
        array $scores,
        float $removeScore,
        array $used,
        float $maxScoreGap,
        int $offset
    ): ?int {
        $available = array_values(array_diff($outside, $used));

        $available = array_values(array_filter(
            $available,
            fn ($number) => (($scores[$number] ?? 0.0) + $maxScoreGap) >= $removeScore
        ));

        if (empty($available)) {
            return null;
        }

        usort($available, fn ($a, $b) => ($scores[$b] ?? 0.0) <=> ($scores[$a] ?? 0.0));

        $index = min(count($available) - 1, $offset % max(1, count($available)));

        return (int) $available[$index];
    }

    protected function isValidVariation(array $variation, array $core, int $minimumCoreOverlap): bool
    {
        return count(array_intersect($variation, $core)) >= $minimumCoreOverlap;
    }

    protected function alreadyExists(array $variation, array $variations): bool
    {
        $key = $this->key($variation);

        foreach ($variations as $existing) {
            if ($this->key($existing) === $key) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeNumberScores(array $numberScores): array
    {
        $scores = [];

        foreach (range(1, 25) as $number) {
            if (isset($numberScores[$number]) && is_array($numberScores[$number])) {
                $scores[$number] = (float) ($numberScores[$number]['score'] ?? 0.0);
            } else {
                $scores[$number] = (float) ($numberScores[$number] ?? 0.0);
            }
        }

        $min = min($scores);
        $max = max($scores);

        if ($max <= $min) {
            return array_fill_keys(range(1, 25), 0.5);
        }

        foreach ($scores as $number => $score) {
            $scores[$number] = ($score - $min) / ($max - $min);
        }

        return $scores;
    }

    protected function key(array $numbers): string
    {
        $numbers = array_values(array_unique(array_map('intval', $numbers)));
        sort($numbers);

        return implode('-', $numbers);
    }
}