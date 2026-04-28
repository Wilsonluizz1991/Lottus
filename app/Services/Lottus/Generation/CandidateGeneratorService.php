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
            $quantidade,
            (int) config('lottus.generator.target_candidates', 200)
        );

        $candidates = [];
        $seen = [];

        $profile = $this->buildProfileFromWeights($weights);

        $lastDraw = $weights['last_draw_numbers'] ?? [];
        $cycleMissing = $weights['faltantes'] ?? [];

        $attempt = 0;

        while (count($candidates) < $targetCandidates && $attempt < $maxAttempts) {
            $attempt++;

            $game = $this->buildGame(
                $frequencyContext,
                $delayContext,
                $correlationContext,
                $weights,
                $lastDraw,
                $cycleMissing,
                $profile
            );

            if (! $this->passesSoftFilters($game, $structureContext, $profile, $lastDraw, $cycleMissing)) {
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

    protected function buildProfileFromWeights(array $weights): array
    {
        $frequency = (float) ($weights['frequency'] ?? config('lottus.weights.frequency', 0.25));
        $delay = (float) ($weights['delay'] ?? config('lottus.weights.delay', 0.25));
        $correlation = (float) ($weights['correlation'] ?? config('lottus.weights.correlation', 0.25));
        $cycle = (float) ($weights['cycle'] ?? config('lottus.weights.cycle', 0.25));

        if ($cycle >= 0.28 || $delay >= 0.28 || $correlation >= 0.28) {
            return [
                'name' => 'aggressive',
                'repeat_min' => 7,
                'repeat_max' => 12,
                'cycle_min_hits' => 1,
                'sum_tolerance' => 26,
                'odd_tolerance' => 3,
                'top_band_initial' => 11,
                'top_band_dynamic' => 15,
                'conviction_gate' => 0.38,
                'elite_gate' => 0.64,
            ];
        }

        if ($frequency >= 0.28) {
            return [
                'name' => 'frequency_biased',
                'repeat_min' => 7,
                'repeat_max' => 12,
                'cycle_min_hits' => 1,
                'sum_tolerance' => 24,
                'odd_tolerance' => 3,
                'top_band_initial' => 12,
                'top_band_dynamic' => 16,
                'conviction_gate' => 0.36,
                'elite_gate' => 0.62,
            ];
        }

        return [
            'name' => 'balanced',
            'repeat_min' => 7,
            'repeat_max' => 12,
            'cycle_min_hits' => 1,
            'sum_tolerance' => 25,
            'odd_tolerance' => 3,
            'top_band_initial' => 12,
            'top_band_dynamic' => 16,
            'conviction_gate' => 0.36,
            'elite_gate' => 0.62,
        ];
    }

    protected function buildGame(
        array $frequencyContext,
        array $delayContext,
        array $correlationContext,
        array $weights,
        array $lastDraw,
        array $cycleMissing,
        array $profile
    ): array {
        $selected = [];

        $allScores = $this->buildBaseScores(
            $frequencyContext,
            $delayContext,
            $correlationContext,
            $weights,
            $lastDraw,
            $cycleMissing
        );

        if (! empty($lastDraw)) {
            $repeatTarget = rand($profile['repeat_min'], $profile['repeat_max']);

            $lastDrawPool = [];

            foreach ($lastDraw as $number) {
                $lastDrawPool[$number] = $allScores[$number]['pick_weight'];
            }

            while (count($selected) < $repeatTarget && ! empty($lastDrawPool)) {
                $picked = $this->weightedPickFromTopBand($lastDrawPool, $profile['top_band_initial']);
                $selected[] = $picked;
                unset($lastDrawPool[$picked]);
            }
        }

        while (count($selected) < 15) {
            $pool = [];
            $remainingSlots = 15 - count($selected);

            foreach (range(1, 25) as $number) {
                if (in_array($number, $selected, true)) {
                    continue;
                }

                $weight = $this->calculateWeight(
                    $number,
                    $selected,
                    $frequencyContext,
                    $delayContext,
                    $correlationContext,
                    $weights,
                    $allScores,
                    $profile,
                    $remainingSlots
                );

                if ($weight <= 0) {
                    continue;
                }

                $pool[$number] = $weight;
            }

            if (empty($pool)) {
                foreach (range(1, 25) as $number) {
                    if (! in_array($number, $selected, true)) {
                        $pool[$number] = max(
                            0.0001,
                            $this->calculateFallbackWeight($number, $selected, $allScores)
                        );
                    }
                }
            }

            $picked = $this->weightedPickFromTopBand($pool, $profile['top_band_dynamic']);
            $selected[] = $picked;
        }

        sort($selected);

        return $selected;
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

        $consensus = [];

        foreach (range(1, 25) as $number) {
            $frequency = (float) ($normalizedFrequency[$number] ?? 0.0);
            $delay = (float) ($normalizedDelay[$number] ?? 0.0);
            $cycle = (float) ($normalizedCycle[$number] ?? 0.0);

            $repeatBoost = in_array($number, $lastDraw, true) ? 0.18 : 0.0;
            $cycleMissingBoost = in_array($number, $cycleMissing, true) ? 0.16 : 0.0;

            $consensusValue =
                ($frequency * 0.34) +
                ($delay * 0.24) +
                ($cycle * 0.30) +
                $repeatBoost +
                $cycleMissingBoost;

            $consensus[$number] = [
                'frequency' => $frequency,
                'delay' => $delay,
                'cycle' => $cycle,
                'consensus' => $consensusValue,
                'pick_weight' => max(0.0001, $consensusValue),
            ];
        }

        return $consensus;
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

    protected function calculateWeight(
        int $number,
        array $selected,
        array $frequencyContext,
        array $delayContext,
        array $correlationContext,
        array $weights,
        array $allScores,
        array $profile,
        int $remainingSlots
    ): float {
        $base = $allScores[$number] ?? [
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
        $sequenceBoost = $this->wouldCreateUsefulSequence($number, $selected) ? 0.08 : 0.0;
        $clusterBoost = $this->wouldStrengthenCluster($number, $selected) ? 0.10 : 0.0;
        $zoneBoost = $this->zoneConvictionBoost($number, $selected);

        $gate = (float) ($profile['conviction_gate'] ?? 0.36);
        $eliteGate = (float) ($profile['elite_gate'] ?? 0.62);

        if ($remainingSlots <= 6) {
            $gate -= 0.08;
        }

        if ($remainingSlots <= 3) {
            $gate -= 0.10;
        }

        if ($consensus < $gate && $correlationStrength < 0.12) {
            return max(0.0001, $this->calculateFallbackWeight($number, $selected, $allScores) * 0.35);
        }

        $weight =
            ($consensus * 2.6) +
            ($correlationStrength * 1.9) +
            ($frequency * 0.4) +
            ($delay * 0.35) +
            ($cycle * 0.55) +
            $sequenceBoost +
            $clusterBoost +
            $zoneBoost;

        if ($consensus >= $eliteGate) {
            $weight *= 1.32;
        }

        if ($correlationStrength >= 0.35) {
            $weight *= 1.18;
        }

        if ($cycle >= 0.60) {
            $weight *= 1.14;
        }

        if ($delay >= 0.60) {
            $weight *= 1.08;
        }

        return max($weight, 0.0001);
    }

    protected function calculateFallbackWeight(int $number, array $selected, array $allScores): float
    {
        $base = $allScores[$number] ?? ['consensus' => 0.1];
        $weight = (float) ($base['consensus'] ?? 0.1);

        if ($this->wouldCreateUsefulSequence($number, $selected)) {
            $weight += 0.04;
        }

        if ($this->wouldStrengthenCluster($number, $selected)) {
            $weight += 0.05;
        }

        return $weight;
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

    protected function zoneConvictionBoost(int $number, array $selected): float
    {
        if (empty($selected)) {
            return 0.0;
        }

        $sameRowCount = 0;
        $sameColumnCount = 0;

        foreach ($selected as $picked) {
            if ($this->rowOf($picked) === $this->rowOf($number)) {
                $sameRowCount++;
            }

            if ($this->columnOf($picked) === $this->columnOf($number)) {
                $sameColumnCount++;
            }
        }

        $boost = 0.0;

        if ($sameRowCount >= 1) {
            $boost += 0.04;
        }

        if ($sameRowCount >= 2) {
            $boost += 0.05;
        }

        if ($sameColumnCount >= 1) {
            $boost += 0.02;
        }

        return $boost;
    }

    protected function rowOf(int $number): int
    {
        return (int) floor(($number - 1) / 5);
    }

    protected function columnOf(int $number): int
    {
        return ($number - 1) % 5;
    }

    protected function wouldStrengthenCluster(int $number, array $selected): bool
    {
        foreach ($selected as $picked) {
            if (abs($picked - $number) <= 2) {
                return true;
            }
        }

        return false;
    }

    protected function passesSoftFilters(
        array $game,
        array $structureContext,
        array $profile,
        array $lastDraw,
        array $cycleMissing
    ): bool {
        if (! $this->passesSoftStructureFilters($game, $structureContext, $profile)) {
            return false;
        }

        if (! $this->passesRepeatControl($game, $lastDraw, $profile)) {
            return false;
        }

        if (! $this->passesCycleControl($game, $cycleMissing, $profile)) {
            return false;
        }

        if (! $this->passesConvictionDensity($game, $lastDraw, $cycleMissing)) {
            return false;
        }

        return true;
    }

    protected function passesConvictionDensity(array $game, array $lastDraw, array $cycleMissing): bool
    {
        $repeatCount = empty($lastDraw) ? 0 : count(array_intersect($game, $lastDraw));
        $cycleHits = empty($cycleMissing) ? 0 : count(array_intersect($game, $cycleMissing));
        $longestSequence = $this->longestSequence($game);
        $clusterCount = $this->clusterCount($game);

        if (! empty($lastDraw) && $repeatCount < 6) {
            return false;
        }

        if (! empty($cycleMissing) && count($cycleMissing) >= 2 && $cycleHits < 1) {
            return false;
        }

        if ($longestSequence < 2 || $longestSequence > 8) {
            return false;
        }

        if ($clusterCount < 1) {
            return false;
        }

        return true;
    }

    protected function clusterCount(array $game): int
    {
        $clusters = 0;
        $run = 1;

        for ($i = 1; $i < count($game); $i++) {
            if ($game[$i] <= $game[$i - 1] + 2) {
                $run++;
            } else {
                if ($run >= 2) {
                    $clusters++;
                }
                $run = 1;
            }
        }

        if ($run >= 2) {
            $clusters++;
        }

        return $clusters;
    }

    protected function passesSoftStructureFilters(array $game, array $structureContext, array $profile): bool
    {
        $sum = array_sum($game);
        $oddCount = count(array_filter($game, fn ($n) => $n % 2 !== 0));

        $sumMin = max(145, (int) (($structureContext['sum_min'] ?? 170) - ($profile['sum_tolerance'] ?? 25)));
        $sumMax = min(235, (int) (($structureContext['sum_max'] ?? 210) + ($profile['sum_tolerance'] ?? 25)));
        $oddMin = max(4, (int) (($structureContext['odd_min'] ?? 6) - ($profile['odd_tolerance'] ?? 3)));
        $oddMax = min(11, (int) (($structureContext['odd_max'] ?? 9) + ($profile['odd_tolerance'] ?? 3)));

        if ($sum < $sumMin || $sum > $sumMax) {
            return false;
        }

        if ($oddCount < $oddMin || $oddCount > $oddMax) {
            return false;
        }

        if ($this->hasLongSequence($game, 9)) {
            return false;
        }

        return true;
    }

    protected function passesRepeatControl(array $game, array $lastDraw, array $profile): bool
    {
        if (empty($lastDraw)) {
            return true;
        }

        $repeatCount = count(array_intersect($game, $lastDraw));

        return $repeatCount >= max(6, $profile['repeat_min'] - 1)
            && $repeatCount <= min(13, $profile['repeat_max'] + 1);
    }

    protected function passesCycleControl(array $game, array $cycleMissing, array $profile): bool
    {
        if (empty($cycleMissing)) {
            return true;
        }

        $hits = count(array_intersect($game, $cycleMissing));

        return $hits >= max(1, ($profile['cycle_min_hits'] ?? 1));
    }

    protected function weightedPickFromTopBand(array $weights, int $bandSize): int
    {
        arsort($weights);

        $topWeights = array_slice($weights, 0, max(1, $bandSize), true);

        return $this->weightedPick($topWeights);
    }

    protected function weightedPick(array $weights): int
    {
        $total = array_sum($weights);

        if ($total <= 0) {
            return (int) array_key_first($weights);
        }

        $random = (mt_rand() / mt_getrandmax()) * $total;

        foreach ($weights as $number => $weight) {
            $random -= $weight;

            if ($random <= 0) {
                return (int) $number;
            }
        }

        return (int) array_key_last($weights);
    }

    protected function wouldCreateUsefulSequence(int $number, array $selected): bool
    {
        if (empty($selected)) {
            return false;
        }

        $numbers = $selected;
        $numbers[] = $number;
        sort($numbers);

        $longest = $this->longestSequence($numbers);

        return $longest >= 3 && $longest <= 5;
    }

    protected function longestSequence(array $game): int
    {
        if (empty($game)) {
            return 0;
        }

        $current = 1;
        $longest = 1;

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

    protected function hasLongSequence(array $game, int $maxSequenceLength): bool
    {
        return $this->longestSequence($game) >= $maxSequenceLength;
    }
}