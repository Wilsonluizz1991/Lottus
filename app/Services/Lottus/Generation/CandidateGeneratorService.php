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
        $targetAttempts = (int) config('lottus.generator.attempts', max(2500, $quantidade * 1500));
        $targetCandidates = (int) config('lottus.generator.target_candidates', max(120, $quantidade * 40));

        $candidates = [];
        $seen = [];

        $profile = $this->buildProfileFromWeights($weights);

        $cycleMissing = $weights['faltantes'] ?? [];
        $lastDraw = $weights['last_draw_numbers'] ?? [];

        for ($i = 0; $i < $targetAttempts; $i++) {
            if (count($candidates) >= $targetCandidates) {
                break;
            }

            $game = $this->buildGameWithRepeatControl(
                $frequencyContext,
                $delayContext,
                $correlationContext,
                $weights,
                $profile,
                $lastDraw
            );

            if (! $this->isValidCandidate($game, $structureContext, $profile, $lastDraw, $cycleMissing)) {
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
        $delay = $weights['delay'] ?? 0.25;
        $correlation = $weights['correlation'] ?? 0.25;
        $cycle = $weights['cycle'] ?? 0.25;

        if ($cycle >= 0.28 && $correlation >= 0.27) {
            return [
                'name' => 'correlation_cycle_explosive',
                'sum_tolerance' => 8,
                'odd_tolerance' => 1,
                'repeat_min' => 9,
                'repeat_max' => 10,
                'cycle_min_hits' => 3,
            ];
        }

        if ($cycle >= 0.28 && $delay >= 0.28) {
            return [
                'name' => 'delay_cycle_explosive',
                'sum_tolerance' => 8,
                'odd_tolerance' => 1,
                'repeat_min' => 9,
                'repeat_max' => 10,
                'cycle_min_hits' => 3,
            ];
        }

        return [
            'name' => 'balanced',
            'sum_tolerance' => 5,
            'odd_tolerance' => 1,
            'repeat_min' => 9,
            'repeat_max' => 10,
            'cycle_min_hits' => 2,
        ];
    }

    protected function buildGameWithRepeatControl(
        array $frequencyContext,
        array $delayContext,
        array $correlationContext,
        array $weights,
        array $profile,
        array $lastDraw
    ): array {
        $selected = [];

        if (! empty($lastDraw)) {
            $lastDrawWeights = [];

            foreach ($lastDraw as $number) {
                $lastDrawWeights[$number] = $this->calculateWeight(
                    $number,
                    $selected,
                    $frequencyContext,
                    $delayContext,
                    $correlationContext,
                    $weights
                );
            }

            $repeatCount = rand($profile['repeat_min'], $profile['repeat_max']);

            while (count($selected) < $repeatCount && ! empty($lastDrawWeights)) {
                $picked = $this->weightedPickFromTopBand($lastDrawWeights, 6);
                $selected[] = $picked;
                unset($lastDrawWeights[$picked]);
            }
        }

        $available = array_values(array_diff(range(1, 25), $selected));

        while (count($selected) < 15) {
            $weightsForPick = [];

            foreach ($available as $number) {
                $weightsForPick[$number] = $this->calculateWeight(
                    $number,
                    $selected,
                    $frequencyContext,
                    $delayContext,
                    $correlationContext,
                    $weights
                );
            }

            $picked = $this->weightedPickFromTopBand($weightsForPick, 8);

            $selected[] = $picked;
            $available = array_values(array_diff($available, [$picked]));
        }

        sort($selected);

        return $selected;
    }

    protected function calculateWeight(
        int $number,
        array $selected,
        array $frequencyContext,
        array $delayContext,
        array $correlationContext,
        array $weights
    ): float {
        $frequency = $frequencyContext['scores'][$number] ?? 0.0;
        $delay = $delayContext['scores'][$number] ?? 0.0;
        $cycle = $weights['cycle_scores'][$number] ?? ($weights['scores'][$number] ?? 1.0);
        $correlation = 0.0;

        foreach ($selected as $picked) {
            $correlation += $correlationContext['pair_scores'][$picked][$number] ?? 0.0;
        }

        $baseWeight =
            ($frequency * ($weights['frequency'] ?? config('lottus.weights.frequency', 0.20))) +
            ($delay * ($weights['delay'] ?? config('lottus.weights.delay', 0.30))) +
            ($correlation * ($weights['correlation'] ?? config('lottus.weights.correlation', 0.20))) +
            ($cycle * ($weights['cycle'] ?? config('lottus.weights.cycle', 0.30)));

        if ($cycle > 1.0) {
            $baseWeight *= 1.18;
        }

        if ($delay >= 1.10) {
            $baseWeight *= 1.12;
        }

        if ($correlation > 0) {
            $baseWeight *= 1.08;
        }

        return max(0.0001, $baseWeight);
    }

    protected function weightedPickFromTopBand(array $weights, int $bandSize): int
    {
        arsort($weights);
        $topWeights = array_slice($weights, 0, $bandSize, true);

        return $this->weightedPick($topWeights);
    }

    protected function weightedPick(array $weights): int
    {
        $total = array_sum($weights);
        $random = (mt_rand() / mt_getrandmax()) * $total;

        foreach ($weights as $number => $weight) {
            $random -= $weight;

            if ($random <= 0) {
                return (int) $number;
            }
        }

        return (int) array_key_last($weights);
    }

    protected function isValidCandidate(
        array $game,
        array $structureContext,
        array $profile,
        array $lastDraw,
        array $cycleMissing
    ): bool {
        return
            $this->passesStructureFilters($game, $structureContext, $profile) &&
            $this->passesRepeatControl($game, $lastDraw, $profile) &&
            $this->passesCycleControl($game, $cycleMissing, $profile);
    }

    protected function passesStructureFilters(array $game, array $structureContext, array $profile): bool
    {
        $sum = array_sum($game);
        $oddCount = count(array_filter($game, fn ($n) => $n % 2 !== 0));

        $sumMin = $structureContext['sum_min'] - ($profile['sum_tolerance'] ?? 0);
        $sumMax = $structureContext['sum_max'] + ($profile['sum_tolerance'] ?? 0);
        $oddMin = $structureContext['odd_min'] - ($profile['odd_tolerance'] ?? 0);
        $oddMax = $structureContext['odd_max'] + ($profile['odd_tolerance'] ?? 0);

        if ($sum < $sumMin || $sum > $sumMax) {
            return false;
        }

        if ($oddCount < $oddMin || $oddCount > $oddMax) {
            return false;
        }

        if ($this->hasLongSequence($game, 6)) {
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

        return $repeatCount >= $profile['repeat_min']
            && $repeatCount <= $profile['repeat_max'];
    }

    protected function passesCycleControl(array $game, array $cycleMissing, array $profile): bool
    {
        if (empty($cycleMissing)) {
            return true;
        }

        $hits = count(array_intersect($game, $cycleMissing));

        return $hits >= ($profile['cycle_min_hits'] ?? 0);
    }

    protected function hasLongSequence(array $game, int $maxSequenceLength): bool
    {
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

        return $longest >= $maxSequenceLength;
    }
}