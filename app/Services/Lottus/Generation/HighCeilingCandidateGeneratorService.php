<?php

namespace App\Services\Lottus\Generation;

use Illuminate\Support\Collection;

class HighCeilingCandidateGeneratorService
{
    public function generate(
        int $quantidade,
        array $frequencyContext,
        array $delayContext,
        array $correlationContext,
        array $structureContext,
        array $weights,
        Collection|array|null $historico = null
    ): array {
        if (! (bool) config('lottus.generator.elite.enabled', true)) {
            return [];
        }

        $targetCandidates = max(
            $quantidade,
            (int) config('lottus.generator.elite.target_candidates', 900)
        );
        $maxAttempts = max(
            $targetCandidates,
            (int) config('lottus.generator.elite.attempts', 12000)
        );

        $lastDraw = $this->normalizeNumbers($weights['last_draw_numbers'] ?? []);
        $cycleMissing = $this->normalizeNumbers($weights['faltantes'] ?? []);
        $baseScores = $this->buildBaseScores($frequencyContext, $delayContext, $weights, $lastDraw, $cycleMissing);
        $historicalDraws = $this->historicalDraws($historico);
        $strongPairs = $this->topPairs($correlationContext);
        $strategies = $this->strategies();

        $candidates = [];
        $seen = [];
        $attempt = 0;

        while (count($candidates) < $targetCandidates && $attempt < $maxAttempts) {
            $strategy = $strategies[$attempt % count($strategies)];
            $attempt++;

            $game = $strategy['name'] === 'historical_replay'
                ? $this->buildHistoricalReplayGame(
                    $historicalDraws,
                    $baseScores,
                    $correlationContext,
                    $lastDraw,
                    $cycleMissing,
                    $strategy
                )
                : $this->buildStrategyGame(
                    $baseScores,
                    $correlationContext,
                    $strongPairs,
                    $lastDraw,
                    $cycleMissing,
                    $strategy
                );

            if (count($game) !== 15) {
                continue;
            }

            if (! $this->passesHighCeilingFilters($game, $structureContext, $lastDraw, $cycleMissing, $strategy)) {
                continue;
            }

            $key = $this->gameKey($game);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $candidates[] = [
                'dezenas' => $game,
                'profile' => $strategy['profile'],
                'strategy' => $strategy['name'],
                'cycle_missing' => $cycleMissing,
            ];
        }

        return $candidates;
    }

    protected function strategies(): array
    {
        return [
            [
                'name' => 'elite_high_ceiling',
                'profile' => 'high_ceiling',
                'repeat_min' => 8,
                'repeat_max' => 11,
                'cycle_min' => 2,
                'cycle_max' => 5,
                'top_band' => 21,
                'tail_chance' => 0.05,
                'corr_weight' => 2.70,
                'delay_weight' => 0.72,
                'cycle_weight' => 0.86,
                'seed_pair' => true,
            ],
            [
                'name' => 'correlation_cluster',
                'profile' => 'correlation_cluster',
                'repeat_min' => 7,
                'repeat_max' => 11,
                'cycle_min' => 1,
                'cycle_max' => 4,
                'top_band' => 18,
                'tail_chance' => 0.04,
                'corr_weight' => 3.35,
                'delay_weight' => 0.46,
                'cycle_weight' => 0.58,
                'seed_pair' => true,
            ],
            [
                'name' => 'strategic_repeat',
                'profile' => 'strategic_repeat',
                'repeat_min' => 9,
                'repeat_max' => 12,
                'cycle_min' => 1,
                'cycle_max' => 3,
                'top_band' => 20,
                'tail_chance' => 0.06,
                'corr_weight' => 2.10,
                'delay_weight' => 0.42,
                'cycle_weight' => 0.54,
                'repeat_weight' => 0.55,
            ],
            [
                'name' => 'controlled_delay',
                'profile' => 'controlled_delay',
                'repeat_min' => 6,
                'repeat_max' => 10,
                'cycle_min' => 3,
                'cycle_max' => 6,
                'top_band' => 22,
                'tail_chance' => 0.08,
                'corr_weight' => 1.85,
                'delay_weight' => 1.05,
                'cycle_weight' => 1.15,
            ],
            [
                'name' => 'explosive_hybrid',
                'profile' => 'explosive_hybrid',
                'repeat_min' => 7,
                'repeat_max' => 12,
                'cycle_min' => 2,
                'cycle_max' => 5,
                'top_band' => 24,
                'tail_chance' => 0.12,
                'corr_weight' => 2.30,
                'delay_weight' => 0.80,
                'cycle_weight' => 0.90,
                'seed_pair' => true,
            ],
            [
                'name' => 'anti_mean_high_ceiling',
                'profile' => 'anti_mean',
                'repeat_min' => 6,
                'repeat_max' => 13,
                'cycle_min' => 1,
                'cycle_max' => 5,
                'top_band' => 25,
                'tail_chance' => 0.20,
                'corr_weight' => 2.05,
                'delay_weight' => 0.78,
                'cycle_weight' => 0.76,
                'anti_mean' => true,
            ],
            [
                'name' => 'historical_replay',
                'profile' => 'historical_replay',
                'repeat_min' => 6,
                'repeat_max' => 12,
                'cycle_min' => 1,
                'cycle_max' => 4,
                'top_band' => 21,
                'tail_chance' => 0.07,
                'corr_weight' => 2.40,
                'delay_weight' => 0.70,
                'cycle_weight' => 0.84,
                'mutation_min' => 1,
                'mutation_max' => 3,
            ],
        ];
    }

    protected function buildStrategyGame(
        array $baseScores,
        array $correlationContext,
        array $strongPairs,
        array $lastDraw,
        array $cycleMissing,
        array $strategy
    ): array {
        $selected = [];

        if (! empty($strategy['seed_pair']) && ! empty($strongPairs)) {
            $pair = $strongPairs[array_rand(array_slice($strongPairs, 0, min(35, count($strongPairs)), true))];
            $this->addNumber($selected, $pair[0]);
            $this->addNumber($selected, $pair[1]);
        }

        $repeatTarget = empty($lastDraw)
            ? 0
            : rand((int) $strategy['repeat_min'], (int) $strategy['repeat_max']);

        while (count(array_intersect($selected, $lastDraw)) < $repeatTarget && count($selected) < 15) {
            $pool = $this->poolFromNumbers($lastDraw, $selected, $baseScores, $correlationContext, $strategy);

            if (empty($pool)) {
                break;
            }

            $this->addNumber(
                $selected,
                $this->weightedPickFromBand($pool, (int) $strategy['top_band'], (float) $strategy['tail_chance'])
            );
        }

        $cycleTarget = empty($cycleMissing)
            ? 0
            : min(count($cycleMissing), rand((int) $strategy['cycle_min'], (int) $strategy['cycle_max']));

        while (count(array_intersect($selected, $cycleMissing)) < $cycleTarget && count($selected) < 15) {
            $pool = $this->poolFromNumbers($cycleMissing, $selected, $baseScores, $correlationContext, $strategy);

            if (empty($pool)) {
                break;
            }

            $this->addNumber(
                $selected,
                $this->weightedPickFromBand($pool, (int) $strategy['top_band'], (float) $strategy['tail_chance'])
            );
        }

        return $this->fillGame($selected, $baseScores, $correlationContext, $strategy);
    }

    protected function buildHistoricalReplayGame(
        array $historicalDraws,
        array $baseScores,
        array $correlationContext,
        array $lastDraw,
        array $cycleMissing,
        array $strategy
    ): array {
        if (empty($historicalDraws)) {
            return $this->buildStrategyGame($baseScores, $correlationContext, [], $lastDraw, $cycleMissing, $strategy);
        }

        $recentPool = array_slice($historicalDraws, -240);
        $game = $recentPool[array_rand($recentPool)];
        $mutationCount = rand((int) $strategy['mutation_min'], (int) $strategy['mutation_max']);

        for ($i = 0; $i < $mutationCount; $i++) {
            if (count($game) <= 11) {
                break;
            }

            $removalScores = [];

            foreach ($game as $number) {
                $base = $baseScores[$number] ?? ['consensus' => 0.0, 'delay' => 0.0, 'cycle' => 0.0];
                $removalScores[$number] = 1.0 - (
                    ((float) $base['consensus'] * 0.58) +
                    ((float) $base['delay'] * 0.20) +
                    ((float) $base['cycle'] * 0.22)
                );
            }

            arsort($removalScores);
            $removePool = array_slice($removalScores, 0, min(6, count($removalScores)), true);
            $remove = $this->weightedPick($removePool);
            $game = array_values(array_diff($game, [$remove]));
        }

        $game = $this->fillGame($game, $baseScores, $correlationContext, $strategy);

        return $game;
    }

    protected function fillGame(
        array $selected,
        array $baseScores,
        array $correlationContext,
        array $strategy
    ): array {
        while (count($selected) < 15) {
            $pool = [];

            foreach (range(1, 25) as $number) {
                if (in_array($number, $selected, true)) {
                    continue;
                }

                $pool[$number] = $this->dynamicWeight($number, $selected, $baseScores, $correlationContext, $strategy);
            }

            if (empty($pool)) {
                break;
            }

            $this->addNumber(
                $selected,
                $this->weightedPickFromBand($pool, (int) $strategy['top_band'], (float) $strategy['tail_chance'])
            );
        }

        $selected = $this->normalizeNumbers($selected);

        return array_slice($selected, 0, 15);
    }

    protected function poolFromNumbers(
        array $numbers,
        array $selected,
        array $baseScores,
        array $correlationContext,
        array $strategy
    ): array {
        $pool = [];

        foreach ($numbers as $number) {
            if (in_array($number, $selected, true)) {
                continue;
            }

            $pool[$number] = $this->dynamicWeight($number, $selected, $baseScores, $correlationContext, $strategy);
        }

        return $pool;
    }

    protected function dynamicWeight(
        int $number,
        array $selected,
        array $baseScores,
        array $correlationContext,
        array $strategy
    ): float {
        $base = $baseScores[$number] ?? [
            'frequency' => 0.0,
            'delay' => 0.0,
            'cycle' => 0.0,
            'consensus' => 0.0,
            'repeat' => 0.0,
        ];

        $correlation = $this->correlationWithSelected($number, $selected, $correlationContext);
        $consensus = (float) $base['consensus'];
        $delay = (float) $base['delay'];
        $cycle = (float) $base['cycle'];
        $frequency = (float) $base['frequency'];
        $repeat = (float) $base['repeat'];

        $weight =
            ($consensus * 2.15) +
            ($frequency * 0.48) +
            ($delay * (float) ($strategy['delay_weight'] ?? 0.70)) +
            ($cycle * (float) ($strategy['cycle_weight'] ?? 0.70)) +
            ($correlation * (float) ($strategy['corr_weight'] ?? 2.20)) +
            ($repeat * (float) ($strategy['repeat_weight'] ?? 0.24));

        if ($correlation >= 0.50) {
            $weight *= 1.22;
        } elseif ($correlation >= 0.40) {
            $weight *= 1.12;
        }

        if (($strategy['anti_mean'] ?? false) && $consensus >= 0.28 && $consensus <= 0.72) {
            $weight *= 1.18;
        }

        if ($this->wouldCreateUsefulSequence($number, $selected)) {
            $weight += 0.12;
        }

        return max(0.0001, $weight);
    }

    protected function buildBaseScores(
        array $frequencyContext,
        array $delayContext,
        array $weights,
        array $lastDraw,
        array $cycleMissing
    ): array {
        $frequencyScores = $this->normalizeScores($frequencyContext['scores'] ?? []);
        $delayScores = $this->normalizeScores($delayContext['scores'] ?? []);
        $cycleScores = $this->normalizeScores($weights['cycle_scores'] ?? $weights['scores'] ?? []);
        $scores = [];

        foreach (range(1, 25) as $number) {
            $frequency = (float) ($frequencyScores[$number] ?? 0.0);
            $delay = (float) ($delayScores[$number] ?? 0.0);
            $cycle = (float) ($cycleScores[$number] ?? 0.0);
            $repeat = in_array($number, $lastDraw, true) ? 1.0 : 0.0;
            $cycleMissingBoost = in_array($number, $cycleMissing, true) ? 1.0 : 0.0;

            $consensus =
                ($frequency * 0.30) +
                ($delay * 0.22) +
                ($cycle * 0.28) +
                ($repeat * 0.12) +
                ($cycleMissingBoost * 0.08);

            $scores[$number] = [
                'frequency' => $frequency,
                'delay' => $delay,
                'cycle' => $cycle,
                'repeat' => $repeat,
                'consensus' => $consensus,
            ];
        }

        return $scores;
    }

    protected function passesHighCeilingFilters(
        array $game,
        array $structureContext,
        array $lastDraw,
        array $cycleMissing,
        array $strategy
    ): bool {
        $sum = array_sum($game);
        $oddCount = count(array_filter($game, fn ($number) => $number % 2 !== 0));
        $repeatCount = empty($lastDraw) ? 0 : count(array_intersect($game, $lastDraw));
        $cycleHits = empty($cycleMissing) ? 0 : count(array_intersect($game, $cycleMissing));
        $longestSequence = $this->longestSequence($game);

        $sumMin = max(135, (int) (($structureContext['sum_min'] ?? 170) - 45));
        $sumMax = min(250, (int) (($structureContext['sum_max'] ?? 210) + 45));

        if ($sum < $sumMin || $sum > $sumMax) {
            return false;
        }

        if ($oddCount < 4 || $oddCount > 11) {
            return false;
        }

        if (! empty($lastDraw) && ($repeatCount < 5 || $repeatCount > 13)) {
            return false;
        }

        if (! empty($cycleMissing) && $cycleHits < min(1, (int) ($strategy['cycle_min'] ?? 1))) {
            return false;
        }

        if ($longestSequence > 10) {
            return false;
        }

        return true;
    }

    protected function topPairs(array $correlationContext): array
    {
        $pairScores = $correlationContext['pair_scores'] ?? [];
        $pairs = [];

        foreach (range(1, 25) as $a) {
            foreach (range($a + 1, 25) as $b) {
                $pairs[] = [$a, $b, (float) ($pairScores[$a][$b] ?? $pairScores[$b][$a] ?? 0.0)];
            }
        }

        usort($pairs, fn ($a, $b) => $b[2] <=> $a[2]);

        return array_slice($pairs, 0, 90);
    }

    protected function historicalDraws(Collection|array|null $historico): array
    {
        if (! $historico) {
            return [];
        }

        $items = $historico instanceof Collection ? $historico->values()->all() : array_values($historico);
        $items = array_slice($items, -320);
        $draws = [];

        foreach ($items as $item) {
            $numbers = [];

            if (is_array($item)) {
                $numbers = $item['dezenas'] ?? $item['numbers'] ?? [];
            }

            $numbers = $this->normalizeNumbers($numbers);

            if (count($numbers) === 15) {
                $draws[] = $numbers;
            }
        }

        return $draws;
    }

    protected function correlationWithSelected(int $number, array $selected, array $correlationContext): float
    {
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

        return $count ? max(0.0, $total / $count) : 0.0;
    }

    protected function weightedPickFromBand(array $weights, int $bandSize, float $tailChance): int
    {
        arsort($weights);

        $bandSize = max(1, min(25, $bandSize));
        $topWeights = array_slice($weights, 0, $bandSize, true);

        if ($tailChance > 0 && mt_rand() / mt_getrandmax() < $tailChance) {
            $tailWeights = array_slice($weights, $bandSize, null, true);

            if (! empty($tailWeights)) {
                return $this->weightedPick($tailWeights);
            }
        }

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

    protected function normalizeScores(array $scores): array
    {
        $filtered = [];

        foreach (range(1, 25) as $number) {
            $filtered[$number] = (float) ($scores[$number] ?? 0.0);
        }

        $min = min($filtered);
        $max = max($filtered);
        $normalized = [];

        foreach ($filtered as $number => $value) {
            $normalized[$number] = $max <= $min ? 0.5 : (($value - $min) / ($max - $min));
        }

        return $normalized;
    }

    protected function normalizeNumbers(array $numbers): array
    {
        $numbers = array_values(array_unique(array_map('intval', $numbers)));
        $numbers = array_values(array_filter($numbers, fn ($number) => $number >= 1 && $number <= 25));
        sort($numbers);

        return $numbers;
    }

    protected function addNumber(array &$selected, int $number): void
    {
        if ($number < 1 || $number > 25 || in_array($number, $selected, true)) {
            return;
        }

        $selected[] = $number;
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

        return $longest >= 3 && $longest <= 6;
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

    protected function gameKey(array $game): string
    {
        $game = $this->normalizeNumbers($game);

        return implode('-', $game);
    }
}
