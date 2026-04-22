<?php

namespace App\Services\Lottus\Generation;

class CandidateGeneratorService
{
    public function generate(
        int $quantidade,
        array $frequencyContext,
        array $delayContext,
        array $correlationContext,
        array $structureContext,
        array $weights
    ): array {
        $maxAttempts = (int) config('lottus.generator.attempts', 2500);
        $targetCandidates = max(
            $quantidade * 60,
            (int) config('lottus.generator.target_candidates', 200)
        );

        $lastDraw = array_values(array_unique(array_map('intval', $weights['last_draw_numbers'] ?? [])));
        sort($lastDraw);

        $cycleMissing = array_values(array_unique(array_map('intval', $weights['faltantes'] ?? [])));
        sort($cycleMissing);

        $baseScores = $this->buildBaseScores(
            $frequencyContext,
            $delayContext,
            $correlationContext,
            $weights,
            $lastDraw,
            $cycleMissing
        );

        $strategies = $this->buildStrategies($lastDraw, $cycleMissing);
        $candidates = [];
        $seen = [];
        $attempt = 0;

        while (count($candidates) < $targetCandidates && $attempt < $maxAttempts) {
            $attempt++;

            $strategy = $strategies[array_rand($strategies)];

            $game = $this->buildGame(
                $baseScores,
                $correlationContext,
                $lastDraw,
                $cycleMissing,
                $strategy
            );

            if (! $this->passesTechnicalIntegrity($game)) {
                continue;
            }

            $key = implode('-', $game);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $candidates[] = $game;
        }

        return $candidates;
    }

    protected function buildStrategies(array $lastDraw, array $cycleMissing): array
    {
        $lastDrawCount = count($lastDraw);
        $cycleCount = count($cycleMissing);

        return [
            [
                'name' => 'trend_soft',
                'repeat_min' => min(6, $lastDrawCount),
                'repeat_max' => min(9, $lastDrawCount),
                'cycle_min' => min(1, $cycleCount),
                'cycle_max' => min(4, max(1, $cycleCount)),
                'band' => 16,
                'exploration' => 0.22,
            ],
            [
                'name' => 'trend_hard',
                'repeat_min' => min(8, $lastDrawCount),
                'repeat_max' => min(11, $lastDrawCount),
                'cycle_min' => min(1, $cycleCount),
                'cycle_max' => min(5, max(1, $cycleCount)),
                'band' => 14,
                'exploration' => 0.18,
            ],
            [
                'name' => 'recovery_cycle',
                'repeat_min' => min(4, $lastDrawCount),
                'repeat_max' => min(8, $lastDrawCount),
                'cycle_min' => min(2, max(0, $cycleCount)),
                'cycle_max' => min(6, max(1, $cycleCount)),
                'band' => 18,
                'exploration' => 0.28,
            ],
            [
                'name' => 'balanced_open',
                'repeat_min' => min(3, $lastDrawCount),
                'repeat_max' => min(10, $lastDrawCount),
                'cycle_min' => 0,
                'cycle_max' => min(5, max(1, $cycleCount)),
                'band' => 20,
                'exploration' => 0.34,
            ],
            [
                'name' => 'chaos_hunt',
                'repeat_min' => min(2, $lastDrawCount),
                'repeat_max' => min(12, $lastDrawCount),
                'cycle_min' => 0,
                'cycle_max' => min(6, max(1, $cycleCount)),
                'band' => 25,
                'exploration' => 0.48,
            ],
        ];
    }

    protected function buildGame(
        array $baseScores,
        array $correlationContext,
        array $lastDraw,
        array $cycleMissing,
        array $strategy
    ): array {
        $selected = [];

        $repeatTarget = empty($lastDraw)
            ? 0
            : rand($strategy['repeat_min'], max($strategy['repeat_min'], $strategy['repeat_max']));

        $cycleTarget = empty($cycleMissing)
            ? 0
            : rand($strategy['cycle_min'], max($strategy['cycle_min'], $strategy['cycle_max']));

        if ($repeatTarget > 0 && ! empty($lastDraw)) {
            $repeatPool = [];

            foreach ($lastDraw as $number) {
                $repeatPool[$number] = $this->seedWeight($number, $baseScores, true, false);
            }

            while (count($selected) < $repeatTarget && ! empty($repeatPool)) {
                $picked = $this->weightedPickExploration($repeatPool, $strategy['band']);
                $selected[] = $picked;
                unset($repeatPool[$picked]);
            }
        }

        if ($cycleTarget > 0 && ! empty($cycleMissing)) {
            $cyclePool = [];

            foreach ($cycleMissing as $number) {
                if (in_array($number, $selected, true)) {
                    continue;
                }

                $cyclePool[$number] = $this->seedWeight($number, $baseScores, false, true);
            }

            while (
                count(array_intersect($selected, $cycleMissing)) < $cycleTarget
                && ! empty($cyclePool)
                && count($selected) < 15
            ) {
                $picked = $this->weightedPickExploration($cyclePool, $strategy['band']);
                $selected[] = $picked;
                unset($cyclePool[$picked]);
            }
        }

        while (count($selected) < 15) {
            $pool = [];
            $remainingSlots = 15 - count($selected);

            foreach (range(1, 25) as $number) {
                if (in_array($number, $selected, true)) {
                    continue;
                }

                $pool[$number] = $this->calculateNumberWeight(
                    $number,
                    $selected,
                    $baseScores,
                    $correlationContext,
                    $lastDraw,
                    $cycleMissing,
                    $strategy,
                    $remainingSlots
                );
            }

            $picked = $this->weightedPickExploration($pool, $strategy['band']);
            $selected[] = $picked;
        }

        sort($selected);

        return $selected;
    }

    protected function seedWeight(int $number, array $baseScores, bool $repeatSeed, bool $cycleSeed): float
    {
        $base = $baseScores[$number] ?? [
            'consensus' => 0.1,
            'frequency' => 0.1,
            'delay' => 0.1,
            'cycle' => 0.1,
        ];

        $weight =
            ((float) $base['consensus'] * 2.0) +
            ((float) $base['frequency'] * 0.4) +
            ((float) $base['delay'] * 0.5) +
            ((float) $base['cycle'] * 0.7);

        if ($repeatSeed) {
            $weight *= 1.10;
        }

        if ($cycleSeed) {
            $weight *= 1.18;
        }

        return max($weight, 0.0001);
    }

    protected function calculateNumberWeight(
        int $number,
        array $selected,
        array $baseScores,
        array $correlationContext,
        array $lastDraw,
        array $cycleMissing,
        array $strategy,
        int $remainingSlots
    ): float {
        $base = $baseScores[$number] ?? [
            'frequency' => 0.0,
            'delay' => 0.0,
            'cycle' => 0.0,
            'consensus' => 0.0,
            'pick_weight' => 0.0001,
        ];

        $consensus = (float) $base['consensus'];
        $frequency = (float) $base['frequency'];
        $delay = (float) $base['delay'];
        $cycle = (float) $base['cycle'];

        $correlationStrength = $this->correlationWithSelected($number, $selected, $correlationContext);
        $isLastDraw = in_array($number, $lastDraw, true);
        $isCycleMissing = in_array($number, $cycleMissing, true);

        $weight =
            ($consensus * 1.85) +
            ($frequency * 0.40) +
            ($delay * 0.55) +
            ($cycle * 0.80) +
            ($correlationStrength * 3.40);

        if ($isLastDraw) {
            $weight += 0.18;
        }

        if ($isCycleMissing) {
            $weight += 0.24;
        }

        if ($this->wouldCreateSequence($number, $selected)) {
            $weight += 0.22;
        }

        if ($this->wouldCreateCluster($number, $selected)) {
            $weight += 0.24;
        }

        if ($this->wouldCloseGap($number, $selected)) {
            $weight += 0.18;
        }

        if ($remainingSlots <= 5) {
            $weight *= 1.12;
        }

        if ($remainingSlots <= 3) {
            $weight *= 1.16;
        }

        if ($strategy['exploration'] > 0) {
            $noiseMin = max(0.55, 1 - $strategy['exploration']);
            $noiseMax = 1 + $strategy['exploration'];
            $weight *= $this->randomFloat($noiseMin, $noiseMax);
        }

        return max($weight, 0.0001);
    }

    protected function buildBaseScores(
        array $frequencyContext,
        array $delayContext,
        array $correlationContext,
        array $weights,
        array $lastDraw,
        array $cycleMissing
    ): array {
        $frequencyScores = $frequencyContext['scores'] ?? [];
        $delayScores = $delayContext['scores'] ?? [];
        $cycleScores = $weights['cycle_scores'] ?? $weights['scores'] ?? [];

        $normalizedFrequency = $this->normalizeScores($frequencyScores);
        $normalizedDelay = $this->normalizeScores($delayScores);
        $normalizedCycle = $this->normalizeScores($cycleScores);

        $baseScores = [];

        foreach (range(1, 25) as $number) {
            $frequency = (float) ($normalizedFrequency[$number] ?? 0.0);
            $delay = (float) ($normalizedDelay[$number] ?? 0.0);
            $cycle = (float) ($normalizedCycle[$number] ?? 0.0);

            $repeatBoost = in_array($number, $lastDraw, true) ? 0.14 : 0.0;
            $cycleMissingBoost = in_array($number, $cycleMissing, true) ? 0.18 : 0.0;

            $consensus =
                ($frequency * 0.36) +
                ($delay * 0.27) +
                ($cycle * 0.37) +
                $repeatBoost +
                $cycleMissingBoost;

            $baseScores[$number] = [
                'frequency' => $frequency,
                'delay' => $delay,
                'cycle' => $cycle,
                'consensus' => $consensus,
                'pick_weight' => max(0.0001, $consensus),
            ];
        }

        return $baseScores;
    }

    protected function normalizeScores(array $scores): array
    {
        $result = [];
        $filtered = [];

        foreach (range(1, 25) as $number) {
            $filtered[$number] = (float) ($scores[$number] ?? 0.0);
        }

        $min = min($filtered);
        $max = max($filtered);

        foreach ($filtered as $number => $value) {
            if ($max <= $min) {
                $result[$number] = 0.5;
                continue;
            }

            $result[$number] = ($value - $min) / ($max - $min);
        }

        return $result;
    }

    protected function correlationWithSelected(
        int $number,
        array $selected,
        array $correlationContext
    ): float {
        if (empty($selected)) {
            return 0.0;
        }

        $pairScores = $correlationContext['pair_scores'] ?? [];
        $total = 0.0;
        $count = 0;

        foreach ($selected as $picked) {
            $total += (float) ($pairScores[$picked][$number] ?? $pairScores[$number][$picked] ?? 0.0);
            $count++;
        }

        if ($count === 0) {
            return 0.0;
        }

        return max(0.0, $total / $count);
    }

    protected function wouldCreateSequence(int $number, array $selected): bool
    {
        foreach ($selected as $picked) {
            if (abs($picked - $number) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function wouldCreateCluster(int $number, array $selected): bool
    {
        foreach ($selected as $picked) {
            if (abs($picked - $number) <= 2) {
                return true;
            }
        }

        return false;
    }

    protected function wouldCloseGap(int $number, array $selected): bool
    {
        foreach ($selected as $picked) {
            if (abs($picked - $number) === 2) {
                $middle = (int) (($picked + $number) / 2);

                if (! in_array($middle, $selected, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function passesTechnicalIntegrity(array $game): bool
    {
        if (count($game) !== 15) {
            return false;
        }

        $normalized = array_map('intval', $game);
        sort($normalized);

        if (count(array_unique($normalized)) !== 15) {
            return false;
        }

        foreach ($normalized as $number) {
            if ($number < 1 || $number > 25) {
                return false;
            }
        }

        return true;
    }

    protected function weightedPickExploration(array $weights, int $bandSize): int
    {
        arsort($weights);

        $bandLimit = max(5, min(25, $bandSize));
        $band = array_slice($weights, 0, $bandLimit, true);

        if (mt_rand(1, 100) <= 18) {
            $band = array_slice($weights, 0, min(25, max($bandLimit + 6, 12)), true);
        }

        return $this->weightedPick($band);
    }

    protected function weightedPick(array $weights): int
    {
        $total = array_sum($weights);

        if ($total <= 0) {
            return (int) array_key_first($weights);
        }

        $random = $this->randomFloat(0, $total);

        foreach ($weights as $number => $weight) {
            $random -= $weight;

            if ($random <= 0) {
                return (int) $number;
            }
        }

        return (int) array_key_last($weights);
    }

    protected function randomFloat(float $min, float $max): float
    {
        return $min + ((mt_rand() / mt_getrandmax()) * ($max - $min));
    }
}