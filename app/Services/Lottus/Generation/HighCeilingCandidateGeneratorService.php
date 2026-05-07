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
        $learningContext = $weights['main_learning'] ?? [];
        $baseScores = $this->buildBaseScores($frequencyContext, $delayContext, $weights, $lastDraw, $cycleMissing);
        $baseScores = $this->applyLearningBiases($baseScores, $learningContext);
        $historicalDraws = $this->historicalDraws($historico);
        $strongPairs = $this->topPairs($correlationContext);
        $strategies = $this->strategies($learningContext);

        $candidates = [];
        $seen = [];

        foreach ($this->buildDeterministicHighCeilingGames(
            $baseScores,
            $correlationContext,
            $strongPairs,
            $lastDraw,
            $cycleMissing,
            $historicalDraws,
            $structureContext
        ) as $candidate) {
            $game = $candidate['dezenas'] ?? [];
            $key = $this->gameKey($game);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $candidates[] = $candidate;
        }

        foreach ($this->buildAdaptiveStatisticalGames(
            $baseScores,
            $correlationContext,
            $strongPairs,
            $lastDraw,
            $cycleMissing,
            $historicalDraws,
            $structureContext,
            $learningContext,
            $candidates
        ) as $candidate) {
            $game = $candidate['dezenas'] ?? [];
            $key = $this->gameKey($game);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $candidates[] = $candidate;
        }

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

    protected function buildDeterministicHighCeilingGames(
        array $baseScores,
        array $correlationContext,
        array $strongPairs,
        array $lastDraw,
        array $cycleMissing,
        array $historicalDraws,
        array $structureContext
    ): array {
        if (! (bool) config('lottus.generator.elite.deterministic.enabled', true)) {
            return [];
        }

        $limit = max(0, (int) config('lottus.generator.elite.deterministic.limit', 180));

        if ($limit <= 0) {
            return [];
        }

        $recipes = $this->deterministicRecipes();
        $payloads = [];
        $seen = [];

        foreach ($recipes as $recipe) {
            if (count($payloads) >= $limit) {
                break;
            }

            foreach ($this->buildDeterministicRecipeGames(
                $recipe,
                $baseScores,
                $correlationContext,
                $strongPairs,
                $lastDraw,
                $cycleMissing,
                $historicalDraws,
                $structureContext
            ) as $game) {
                if (count($payloads) >= $limit) {
                    break 2;
                }

                $game = $this->normalizeNumbers($game);

                if (count($game) !== 15) {
                    continue;
                }

                $key = $this->gameKey($game);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $payloads[] = [
                    'dezenas' => $game,
                    'profile' => $recipe['profile'],
                    'strategy' => $recipe['name'],
                    'cycle_missing' => $cycleMissing,
                ];
            }
        }

        return $payloads;
    }

    protected function deterministicRecipes(): array
    {
        return [
            [
                'name' => 'deterministic_high_ceiling',
                'profile' => 'high_ceiling',
                'repeat_targets' => [8, 9, 10, 11],
                'cycle_targets' => [2, 3, 4],
                'fill_modes' => ['consensus', 'correlation', 'delay', 'cycle', 'hybrid'],
            ],
            [
                'name' => 'cycle_missing_rescue',
                'profile' => 'controlled_delay',
                'repeat_targets' => [8, 9, 10],
                'cycle_targets' => [3, 4, 5],
                'fill_modes' => ['cycle', 'delay', 'hybrid', 'correlation'],
            ],
            [
                'name' => 'forced_pair_synergy_core',
                'profile' => 'correlation_cluster',
                'repeat_targets' => [7, 8, 9, 10],
                'cycle_targets' => [1, 2, 3],
                'fill_modes' => ['correlation', 'hybrid', 'consensus'],
                'seed_pairs' => true,
            ],
            [
                'name' => 'repeat_pressure_core',
                'profile' => 'strategic_repeat',
                'repeat_targets' => [9, 10, 11],
                'cycle_targets' => [1, 2, 3],
                'fill_modes' => ['consensus', 'correlation', 'hybrid'],
            ],
            [
                'name' => 'historical_elite_mutation',
                'profile' => 'historical_replay',
                'repeat_targets' => [8, 9, 10],
                'cycle_targets' => [1, 2, 3],
                'fill_modes' => ['historical', 'hybrid', 'correlation'],
                'historical' => true,
            ],
        ];
    }

    protected function buildDeterministicRecipeGames(
        array $recipe,
        array $baseScores,
        array $correlationContext,
        array $strongPairs,
        array $lastDraw,
        array $cycleMissing,
        array $historicalDraws,
        array $structureContext
    ): array {
        $games = [];
        $repeatTargets = $recipe['repeat_targets'] ?? [9];
        $cycleTargets = $recipe['cycle_targets'] ?? [2];
        $fillModes = $recipe['fill_modes'] ?? ['hybrid'];
        $seedPairs = ! empty($recipe['seed_pairs'])
            ? array_slice($strongPairs, 0, min(8, count($strongPairs)))
            : [[null, null, 0.0]];

        foreach ($repeatTargets as $repeatTarget) {
            foreach ($cycleTargets as $cycleTarget) {
                foreach ($fillModes as $fillMode) {
                    foreach ($seedPairs as $pair) {
                        $selected = [];

                        if (is_array($pair) && $pair[0] !== null && $pair[1] !== null) {
                            $this->addNumber($selected, (int) $pair[0]);
                            $this->addNumber($selected, (int) $pair[1]);
                        }

                        if (! empty($recipe['historical'])) {
                            $selected = $this->seedHistoricalCore($selected, $historicalDraws, $baseScores, $correlationContext);
                        }

                        $selected = $this->takeBestNumbers(
                            $selected,
                            $lastDraw,
                            (int) $repeatTarget,
                            $baseScores,
                            $correlationContext,
                            $fillMode
                        );

                        $selected = $this->takeBestNumbers(
                            $selected,
                            $cycleMissing,
                            min(count($cycleMissing), (int) $cycleTarget),
                            $baseScores,
                            $correlationContext,
                            $fillMode
                        );

                        $selected = $this->fillDeterministicGame(
                            $selected,
                            $baseScores,
                            $correlationContext,
                            $fillMode
                        );

                        if ($this->passesHighCeilingFilters($selected, $structureContext, $lastDraw, $cycleMissing, [
                            'cycle_min' => 1,
                        ])) {
                            $games[] = $selected;
                        }
                    }
                }
            }
        }

        return $games;
    }

    protected function seedHistoricalCore(
        array $selected,
        array $historicalDraws,
        array $baseScores,
        array $correlationContext
    ): array {
        if (empty($historicalDraws)) {
            return $selected;
        }

        $recent = array_slice($historicalDraws, -80);
        $ranked = [];

        foreach ($recent as $draw) {
            $ranked[] = [
                'draw' => $draw,
                'value' => $this->setValue($draw, $baseScores, $correlationContext),
            ];
        }

        usort($ranked, fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        foreach (array_slice($ranked, 0, 4) as $item) {
            $numbers = $item['draw'];
            usort($numbers, function (int $a, int $b) use ($baseScores, $correlationContext, $selected): int {
                return $this->deterministicNumberWeight($b, $selected, $baseScores, $correlationContext, 'hybrid')
                    <=>
                    $this->deterministicNumberWeight($a, $selected, $baseScores, $correlationContext, 'hybrid');
            });

            foreach (array_slice($numbers, 0, 5) as $number) {
                $this->addNumber($selected, (int) $number);
            }
        }

        return $selected;
    }

    protected function takeBestNumbers(
        array $selected,
        array $source,
        int $targetCount,
        array $baseScores,
        array $correlationContext,
        string $mode
    ): array {
        if (empty($source) || $targetCount <= 0) {
            return $selected;
        }

        $targetCount = min($targetCount, count($source), 15);
        $currentCount = count(array_intersect($selected, $source));

        while ($currentCount < $targetCount && count($selected) < 15) {
            $pool = [];

            foreach ($source as $number) {
                if (in_array($number, $selected, true)) {
                    continue;
                }

                $pool[$number] = $this->deterministicNumberWeight(
                    (int) $number,
                    $selected,
                    $baseScores,
                    $correlationContext,
                    $mode
                );
            }

            if (empty($pool)) {
                break;
            }

            arsort($pool);
            $this->addNumber($selected, (int) array_key_first($pool));
            $currentCount = count(array_intersect($selected, $source));
        }

        return $selected;
    }

    protected function fillDeterministicGame(
        array $selected,
        array $baseScores,
        array $correlationContext,
        string $mode
    ): array {
        while (count($selected) < 15) {
            $pool = [];

            foreach (range(1, 25) as $number) {
                if (in_array($number, $selected, true)) {
                    continue;
                }

                $pool[$number] = $this->deterministicNumberWeight(
                    $number,
                    $selected,
                    $baseScores,
                    $correlationContext,
                    $mode
                );
            }

            if (empty($pool)) {
                break;
            }

            arsort($pool);
            $this->addNumber($selected, (int) array_key_first($pool));
        }

        return $this->normalizeNumbers($selected);
    }

    protected function deterministicNumberWeight(
        int $number,
        array $selected,
        array $baseScores,
        array $correlationContext,
        string $mode
    ): float {
        $base = $baseScores[$number] ?? [
            'frequency' => 0.0,
            'delay' => 0.0,
            'cycle' => 0.0,
            'consensus' => 0.0,
            'repeat' => 0.0,
        ];

        $correlation = $this->correlationWithSelected($number, $selected, $correlationContext);
        $frequency = (float) $base['frequency'];
        $delay = (float) $base['delay'];
        $cycle = (float) $base['cycle'];
        $consensus = (float) $base['consensus'];
        $repeat = (float) $base['repeat'];

        $weights = match ($mode) {
            'correlation' => [1.55, 0.30, 0.42, 0.36, 3.30, 0.22],
            'delay' => [1.55, 0.28, 1.25, 0.55, 2.05, 0.20],
            'cycle' => [1.45, 0.28, 0.62, 1.35, 1.95, 0.20],
            'historical' => [1.80, 0.52, 0.62, 0.66, 2.20, 0.32],
            'consensus' => [2.55, 0.60, 0.50, 0.52, 1.70, 0.24],
            default => [2.05, 0.45, 0.78, 0.82, 2.45, 0.28],
        };

        $value =
            ($consensus * $weights[0]) +
            ($frequency * $weights[1]) +
            ($delay * $weights[2]) +
            ($cycle * $weights[3]) +
            ($correlation * $weights[4]) +
            ($repeat * $weights[5]);

        if ($this->wouldCreateUsefulSequence($number, $selected)) {
            $value += 0.16;
        }

        return max(0.0001, $value);
    }

    protected function setValue(array $numbers, array $baseScores, array $correlationContext): float
    {
        $value = 0.0;
        $numbers = $this->normalizeNumbers($numbers);

        foreach ($numbers as $number) {
            $base = $baseScores[$number] ?? ['consensus' => 0.0, 'delay' => 0.0, 'cycle' => 0.0];
            $value += ((float) $base['consensus'] * 1.8)
                + ((float) $base['delay'] * 0.5)
                + ((float) $base['cycle'] * 0.5);
        }

        for ($i = 0; $i < count($numbers); $i++) {
            for ($j = $i + 1; $j < count($numbers); $j++) {
                $a = $numbers[$i];
                $b = $numbers[$j];
                $value += (float) ($correlationContext['pair_scores'][$a][$b] ?? $correlationContext['pair_scores'][$b][$a] ?? 0.0) * 0.08;
            }
        }

        return $value;
    }

    protected function strategies(array $learningContext = []): array
    {
        $strategies = [
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

        $weights = $learningContext['strategy_weights'] ?? [];

        if (is_array($weights) && ! empty($weights)) {
            foreach ($strategies as &$strategy) {
                $name = (string) ($strategy['name'] ?? '');
                $multiplier = (float) ($weights[$name] ?? 1.0);

                if ($multiplier <= 0) {
                    continue;
                }

                $strategy['corr_weight'] = (float) ($strategy['corr_weight'] ?? 2.2) * $multiplier;
                $strategy['delay_weight'] = (float) ($strategy['delay_weight'] ?? 0.7) * $multiplier;
                $strategy['cycle_weight'] = (float) ($strategy['cycle_weight'] ?? 0.7) * $multiplier;
                $strategy['top_band'] = max(15, min(25, (int) round((int) ($strategy['top_band'] ?? 21) * min(1.12, $multiplier))));
            }

            unset($strategy);

            usort($strategies, function (array $a, array $b) use ($weights): int {
                return ((float) ($weights[$b['name']] ?? 1.0)) <=> ((float) ($weights[$a['name']] ?? 1.0));
            });
        }

        return $strategies;
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

    public function expandCandidateFamilies(
        array $seedCandidates,
        array $frequencyContext,
        array $delayContext,
        array $correlationContext,
        array $structureContext,
        array $weights,
        Collection|array|null $historico = null
    ): array {
        $lastDraw = $this->normalizeNumbers($weights['last_draw_numbers'] ?? []);
        $cycleMissing = $this->normalizeNumbers($weights['faltantes'] ?? []);
        $learningContext = $weights['main_learning'] ?? [];
        $baseScores = $this->applyLearningBiases(
            $this->buildBaseScores($frequencyContext, $delayContext, $weights, $lastDraw, $cycleMissing),
            $learningContext
        );
        $historicalDraws = $this->historicalDraws($historico);
        $sweepLimit = (int) config('lottus_main_learning.candidate_generation.single_swap_sweep_candidates', 36000);
        $sweepLimit = (int) round($sweepLimit * (float) ($learningContext['aggressiveness']['mutation_depth'] ?? 1.0));
        $doubleSweepLimit = (int) config('lottus_main_learning.candidate_generation.double_swap_sweep_candidates', 52000);
        $doubleSweepLimit = (int) round($doubleSweepLimit * (float) ($learningContext['aggressiveness']['mutation_depth'] ?? 1.0));
        $limit = (int) config('lottus_main_learning.candidate_generation.elite_family_candidates', 1400);
        $limit = (int) round($limit * (float) ($learningContext['aggressiveness']['mutation_depth'] ?? 1.0));

        $payloads = [];
        $seen = [];

        foreach ($this->singleSwapSweepGames($seedCandidates, $baseScores, $correlationContext, $sweepLimit) as $game) {
            $game = $this->normalizeNumbers($game);

            if (count($game) !== 15) {
                continue;
            }

            if (! $this->passesHighCeilingFilters($game, $structureContext, $lastDraw, $cycleMissing, ['cycle_min' => 1])) {
                continue;
            }

            $key = $this->gameKey($game);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $payloads[] = [
                'dezenas' => $game,
                'profile' => 'single_swap_sweep',
                'strategy' => 'single_swap_sweep',
                'cycle_missing' => $cycleMissing,
                'main_learning' => $learningContext,
            ];
        }

        foreach ($this->doubleSwapSweepGames($seedCandidates, $baseScores, $correlationContext, $doubleSweepLimit) as $game) {
            $game = $this->normalizeNumbers($game);

            if (count($game) !== 15) {
                continue;
            }

            if (! $this->passesHighCeilingFilters($game, $structureContext, $lastDraw, $cycleMissing, ['cycle_min' => 1])) {
                continue;
            }

            $key = $this->gameKey($game);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $payloads[] = [
                'dezenas' => $game,
                'profile' => 'double_swap_sweep',
                'strategy' => 'double_swap_sweep',
                'cycle_missing' => $cycleMissing,
                'main_learning' => $learningContext,
            ];
        }

        $rankedSeeds = [];

        foreach ($seedCandidates as $seedCandidate) {
            $seed = $this->normalizeNumbers($seedCandidate['dezenas'] ?? $seedCandidate);

            if (count($seed) !== 15) {
                continue;
            }

            $rankedSeeds[] = [
                'dezenas' => $seed,
                'value' => $this->setValue($seed, $baseScores, $correlationContext)
                    + ((float) ($seedCandidate['elite_potential_score'] ?? 0.0) * 0.20)
                    + ((float) ($seedCandidate['near_15_score'] ?? 0.0) * 2.0),
            ];
        }

        usort($rankedSeeds, fn (array $a, array $b): int => $b['value'] <=> $a['value']);
        $seeds = array_slice($rankedSeeds, 0, max(40, (int) config('lottus_main_learning.candidate_generation.max_family_seed_candidates', 1400)));
        foreach ($seeds as $seedCandidate) {
            if (count($payloads) >= $limit) {
                break;
            }

            $seed = $this->normalizeNumbers($seedCandidate['dezenas'] ?? $seedCandidate);

            if (count($seed) !== 15) {
                continue;
            }

            foreach ($this->mutateSeedGame($seed, $baseScores, $correlationContext, $learningContext, 2) as $game) {
                if (count($payloads) >= $limit) {
                    break 2;
                }

                if (! $this->passesHighCeilingFilters($game, $structureContext, $lastDraw, $cycleMissing, ['cycle_min' => 1])) {
                    continue;
                }

                $key = $this->gameKey($game);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $payloads[] = [
                    'dezenas' => $game,
                    'profile' => 'elite_family_expansion',
                    'strategy' => 'elite_family_expansion',
                    'cycle_missing' => $cycleMissing,
                    'main_learning' => $learningContext,
                ];
            }
        }

        foreach (array_slice($historicalDraws, -120) as $draw) {
            if (count($payloads) >= $limit) {
                break;
            }

            foreach ($this->mutateSeedGame($draw, $baseScores, $correlationContext, $learningContext, 3) as $game) {
                if (count($payloads) >= $limit) {
                    break 2;
                }

                if (! $this->passesHighCeilingFilters($game, $structureContext, $lastDraw, $cycleMissing, ['cycle_min' => 1])) {
                    continue;
                }

                $key = $this->gameKey($game);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $payloads[] = [
                    'dezenas' => $game,
                    'profile' => 'near15_mutation',
                    'strategy' => 'near15_mutation',
                    'cycle_missing' => $cycleMissing,
                    'main_learning' => $learningContext,
                ];
            }
        }

        return $payloads;
    }

    protected function doubleSwapSweepGames(
        array $seedCandidates,
        array $baseScores,
        array $correlationContext,
        int $limit
    ): array {
        $games = [];
        $seen = [];

        foreach ($seedCandidates as $candidate) {
            if (count($games) >= $limit) {
                break;
            }

            $seed = $this->normalizeNumbers($candidate['dezenas'] ?? $candidate);

            if (count($seed) !== 15) {
                continue;
            }

            $removalScores = [];

            foreach ($seed as $number) {
                $removalScores[$number] = $this->deterministicNumberWeight(
                    (int) $number,
                    array_values(array_diff($seed, [$number])),
                    $baseScores,
                    $correlationContext,
                    'hybrid'
                );
            }

            asort($removalScores);
            $removePairs = array_slice($this->pairsFromNumbers(array_slice(array_keys($removalScores), 0, 5)), 0, 10);
            $replacementPool = array_slice(array_values(array_diff(
                $this->rankedNumbers($baseScores, $seed, $correlationContext, 'hybrid'),
                $seed
            )), 0, 8);
            $addPairs = array_slice($this->pairsFromNumbers($replacementPool), 0, 28);

            foreach ($removePairs as $removePair) {
                foreach ($addPairs as $addPair) {
                    $game = array_values(array_diff($seed, $removePair));
                    $game = array_merge($game, $addPair);
                    $game = $this->normalizeNumbers($game);
                    $key = implode('-', $game);

                    if (count($game) !== 15 || isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;
                    $games[] = $game;

                    if (count($games) >= $limit) {
                        break 3;
                    }
                }
            }
        }

        return $games;
    }

    protected function singleSwapSweepGames(
        array $seedCandidates,
        array $baseScores,
        array $correlationContext,
        int $limit
    ): array {
        $games = [];
        $seen = [];

        foreach ($seedCandidates as $candidate) {
            if (count($games) >= $limit) {
                break;
            }

            $seed = $this->normalizeNumbers($candidate['dezenas'] ?? $candidate);

            if (count($seed) !== 15) {
                continue;
            }

            $removalScores = [];

            foreach ($seed as $number) {
                $removalScores[$number] = $this->deterministicNumberWeight(
                    (int) $number,
                    array_values(array_diff($seed, [$number])),
                    $baseScores,
                    $correlationContext,
                    'hybrid'
                );
            }

            asort($removalScores);
            $removePool = array_slice(array_keys($removalScores), 0, 5);
            $replacementPool = array_slice(array_values(array_diff(
                $this->rankedNumbers($baseScores, $seed, $correlationContext, 'hybrid'),
                $seed
            )), 0, 8);

            foreach ($removePool as $remove) {
                foreach ($replacementPool as $add) {
                    $game = array_values(array_diff($seed, [(int) $remove]));
                    $game[] = (int) $add;
                    $game = $this->normalizeNumbers($game);
                    $key = implode('-', $game);

                    if (count($game) !== 15 || isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;
                    $games[] = $game;

                    if (count($games) >= $limit) {
                        break 3;
                    }
                }
            }
        }

        return $games;
    }

    protected function applyLearningBiases(array $baseScores, array $learningContext): array
    {
        if (empty($learningContext)) {
            return $baseScores;
        }

        $numberBias = $learningContext['number_bias'] ?? [];
        $pairBias = $learningContext['pair_bias'] ?? [];
        $pairAggregate = array_fill_keys(range(1, 25), 0.0);

        if (is_array($pairBias)) {
            foreach ($pairBias as $key => $value) {
                [$a, $b] = array_map('intval', explode('-', (string) $key) + [0, 0]);

                if ($a >= 1 && $a <= 25 && $b >= 1 && $b <= 25) {
                    $pairAggregate[$a] += (float) $value;
                    $pairAggregate[$b] += (float) $value;
                }
            }
        }

        foreach (range(1, 25) as $number) {
            $bias = (float) ($numberBias[$number] ?? $numberBias[(string) $number] ?? 0.0);
            $pair = (float) ($pairAggregate[$number] ?? 0.0);
            $baseScores[$number]['consensus'] = max(0.0001, (float) ($baseScores[$number]['consensus'] ?? 0.0) + $bias + ($pair * 0.18));
        }

        return $baseScores;
    }

    protected function buildAdaptiveStatisticalGames(
        array $baseScores,
        array $correlationContext,
        array $strongPairs,
        array $lastDraw,
        array $cycleMissing,
        array $historicalDraws,
        array $structureContext,
        array $learningContext,
        array $existingCandidates
    ): array {
        $payloads = [];
        $seen = [];
        $limits = config('lottus_main_learning.candidate_generation', []);
        $candidateMultiplier = (float) ($learningContext['aggressiveness']['candidate_multiplier'] ?? 1.0);

        $groups = [
            ['combinational_envelope', $this->combinationalEnvelopeGames($baseScores, $correlationContext, $strongPairs, $lastDraw, $cycleMissing, (int) (($limits['combinational_envelope_candidates'] ?? 14000) * $candidateMultiplier))],
            ['trend_adaptive_core', $this->trendAdaptiveGames($baseScores, $correlationContext, $strongPairs, $lastDraw, $cycleMissing, (int) (($limits['adaptive_trend_candidates'] ?? 900) * $candidateMultiplier))],
            ['pair_lattice_core', $this->pairLatticeGames($baseScores, $correlationContext, $strongPairs, $lastDraw, $cycleMissing, (int) (($limits['pair_lattice_candidates'] ?? 700) * $candidateMultiplier))],
            ['near15_mutation', $this->near15MutationGames($historicalDraws, $baseScores, $correlationContext, $learningContext, (int) (($limits['near15_mutation_candidates'] ?? 900) * $candidateMultiplier))],
            ['elite_family_expansion', $this->familyExpansionGames($existingCandidates, $baseScores, $correlationContext, $learningContext, (int) (($limits['elite_family_candidates'] ?? 1400) * $candidateMultiplier))],
        ];

        foreach ($groups as [$strategy, $games]) {
            foreach ($games as $game) {
                $game = $this->normalizeNumbers($game);

                if (count($game) !== 15) {
                    continue;
                }

                if (! $this->passesHighCeilingFilters($game, $structureContext, $lastDraw, $cycleMissing, ['cycle_min' => 1])) {
                    continue;
                }

                $key = $this->gameKey($game);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $payloads[] = [
                    'dezenas' => $game,
                    'profile' => $strategy,
                    'strategy' => $strategy,
                    'cycle_missing' => $cycleMissing,
                    'main_learning' => $learningContext,
                ];
            }
        }

        return $payloads;
    }

    protected function combinationalEnvelopeGames(
        array $baseScores,
        array $correlationContext,
        array $strongPairs,
        array $lastDraw,
        array $cycleMissing,
        int $limit
    ): array {
        if ($limit <= 0) {
            return [];
        }

        $games = [];
        $seenBases = [];
        $modes = ['hybrid', 'correlation', 'cycle', 'delay', 'consensus'];
        $repeatTargets = [8, 9, 10, 11, 12];
        $cycleTargets = [1, 2, 3, 4, 5, 6];
        $baseSizes = [19, 20, 21, 22];
        $pairSeeds = array_slice($strongPairs, 0, 16);

        foreach ($baseSizes as $baseSize) {
            foreach ($repeatTargets as $repeatTarget) {
                foreach ($cycleTargets as $cycleTarget) {
                    foreach ($modes as $mode) {
                        $base = [];
                        $base = $this->takeBestNumbers($base, $lastDraw, min($repeatTarget, count($lastDraw)), $baseScores, $correlationContext, $mode);
                        $base = $this->takeBestNumbers($base, $cycleMissing, min($cycleTarget, count($cycleMissing)), $baseScores, $correlationContext, $mode);
                        $base = $this->fillEnvelopeBase($base, $baseSize, $baseScores, $correlationContext, $mode);
                        $this->appendEnvelopeGames($games, $seenBases, $base, $baseScores, $correlationContext, $limit);

                        if (count($games) >= $limit) {
                            return $games;
                        }
                    }
                }
            }
        }

        foreach ($pairSeeds as $pair) {
        foreach ([19, 20, 21, 22] as $baseSize) {
                foreach ($modes as $mode) {
                    $base = [(int) $pair[0], (int) $pair[1]];
                    $base = $this->takeBestNumbers($base, $lastDraw, 9, $baseScores, $correlationContext, $mode);
                    $base = $this->takeBestNumbers($base, $cycleMissing, 3, $baseScores, $correlationContext, $mode);
                    $base = $this->fillEnvelopeBase($base, $baseSize, $baseScores, $correlationContext, $mode);
                    $this->appendEnvelopeGames($games, $seenBases, $base, $baseScores, $correlationContext, $limit);

                    if (count($games) >= $limit) {
                        return $games;
                    }
                }
            }
        }

        return $games;
    }

    protected function fillEnvelopeBase(
        array $selected,
        int $baseSize,
        array $baseScores,
        array $correlationContext,
        string $mode
    ): array {
        while (count($selected) < $baseSize) {
            $pool = [];

            foreach (range(1, 25) as $number) {
                if (in_array($number, $selected, true)) {
                    continue;
                }

                $pool[$number] = $this->deterministicNumberWeight($number, $selected, $baseScores, $correlationContext, $mode);
            }

            if (empty($pool)) {
                break;
            }

            arsort($pool);
            $this->addNumber($selected, (int) array_key_first($pool));
        }

        return $this->normalizeNumbers($selected);
    }

    protected function appendEnvelopeGames(
        array &$games,
        array &$seenBases,
        array $base,
        array $baseScores,
        array $correlationContext,
        int $limit
    ): void {
        $base = $this->normalizeNumbers($base);

        if (count($base) < 15) {
            return;
        }

        $baseKey = implode('-', $base);

        if (isset($seenBases[$baseKey])) {
            return;
        }

        $seenBases[$baseKey] = true;
        $excludeCount = count($base) - 15;
        $exclusionPool = $base;

        if ($excludeCount > 5) {
            $weightedBase = [];

            foreach ($base as $number) {
                $weightedBase[$number] = $this->deterministicNumberWeight((int) $number, array_values(array_diff($base, [$number])), $baseScores, $correlationContext, 'hybrid');
            }

            asort($weightedBase);
            $exclusionPool = array_slice(array_keys($weightedBase), 0, min(count($base), max($excludeCount + 3, 14)));
        }

        $exclusions = $this->exclusionCombinations($exclusionPool, $excludeCount);
        $weighted = [];

        foreach ($exclusions as $excluded) {
            $excludedValue = 0.0;

            foreach ($excluded as $number) {
                $excludedValue += $this->deterministicNumberWeight((int) $number, array_values(array_diff($base, [$number])), $baseScores, $correlationContext, 'hybrid');
            }

            $game = array_values(array_diff($base, $excluded));
            $weighted[] = [
                'game' => $this->normalizeNumbers($game),
                'value' => $excludedValue,
            ];
        }

        usort($weighted, fn (array $a, array $b): int => $a['value'] <=> $b['value']);

        foreach ($weighted as $item) {
            if (count($games) >= $limit) {
                return;
            }

            if (count($item['game']) === 15) {
                $games[] = $item['game'];
            }
        }
    }

    protected function exclusionCombinations(array $numbers, int $take): array
    {
        if ($take <= 0) {
            return [[]];
        }

        $numbers = array_values($numbers);
        $result = [];
        $this->buildExclusionCombinations($numbers, $take, 0, [], $result);

        return $result;
    }

    protected function buildExclusionCombinations(array $numbers, int $take, int $start, array $current, array &$result): void
    {
        if (count($current) === $take) {
            $result[] = $current;

            return;
        }

        for ($i = $start; $i < count($numbers); $i++) {
            $next = $current;
            $next[] = (int) $numbers[$i];
            $this->buildExclusionCombinations($numbers, $take, $i + 1, $next, $result);
        }
    }

    protected function trendAdaptiveGames(
        array $baseScores,
        array $correlationContext,
        array $strongPairs,
        array $lastDraw,
        array $cycleMissing,
        int $limit
    ): array {
        $games = [];
        $repeatTargets = [6, 7, 8, 9, 10, 11, 12, 13];
        $cycleTargets = [1, 2, 3, 4, 5, 6];
        $modes = ['hybrid', 'correlation', 'delay', 'cycle', 'consensus'];
        $seedPairs = array_slice($strongPairs, 0, 28);

        foreach ($repeatTargets as $repeatTarget) {
            foreach ($cycleTargets as $cycleTarget) {
                foreach ($modes as $mode) {
                    $selected = $this->takeBestNumbers([], $lastDraw, min($repeatTarget, count($lastDraw)), $baseScores, $correlationContext, $mode);
                    $selected = $this->takeBestNumbers($selected, $cycleMissing, min($cycleTarget, count($cycleMissing)), $baseScores, $correlationContext, $mode);
                    $games[] = $this->fillDeterministicGame($selected, $baseScores, $correlationContext, $mode);

                    if (count($games) >= $limit) {
                        return $games;
                    }
                }
            }
        }

        foreach ($seedPairs as $pair) {
            foreach ($modes as $mode) {
                $selected = [];
                $this->addNumber($selected, (int) $pair[0]);
                $this->addNumber($selected, (int) $pair[1]);
                $selected = $this->takeBestNumbers($selected, $lastDraw, 9, $baseScores, $correlationContext, $mode);
                $selected = $this->takeBestNumbers($selected, $cycleMissing, 3, $baseScores, $correlationContext, $mode);
                $games[] = $this->fillDeterministicGame($selected, $baseScores, $correlationContext, $mode);

                if (count($games) >= $limit) {
                    return $games;
                }
            }
        }

        return $games;
    }

    protected function pairLatticeGames(
        array $baseScores,
        array $correlationContext,
        array $strongPairs,
        array $lastDraw,
        array $cycleMissing,
        int $limit
    ): array {
        $games = [];
        $pairs = array_slice($strongPairs, 0, 45);
        $topNumbers = $this->rankedNumbers($baseScores, [], $correlationContext, 'hybrid');

        foreach ($pairs as $pair) {
            $selected = [(int) $pair[0], (int) $pair[1]];

            foreach (array_slice($topNumbers, 0, 18) as $number) {
                if (count($selected) >= 9) {
                    break;
                }

                $this->addNumber($selected, $number);
            }

            foreach ($lastDraw as $number) {
                if (count(array_intersect($selected, $lastDraw)) >= 9) {
                    break;
                }

                $this->addNumber($selected, $number);
            }

            foreach ($cycleMissing as $number) {
                if (count(array_intersect($selected, $cycleMissing)) >= 3) {
                    break;
                }

                $this->addNumber($selected, $number);
            }

            $games[] = $this->fillDeterministicGame($selected, $baseScores, $correlationContext, 'correlation');

            if (count($games) >= $limit) {
                break;
            }
        }

        return $games;
    }

    protected function near15MutationGames(
        array $historicalDraws,
        array $baseScores,
        array $correlationContext,
        array $learningContext,
        int $limit
    ): array {
        if ($limit <= 0) {
            return [];
        }

        $ranked = [];

        foreach (array_slice($historicalDraws, -220) as $draw) {
            $ranked[] = [
                'draw' => $draw,
                'value' => $this->setValue($draw, $baseScores, $correlationContext),
            ];
        }

        usort($ranked, fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        $games = [];

        foreach (array_slice($ranked, 0, 90) as $item) {
            foreach ($this->mutateSeedGame($item['draw'], $baseScores, $correlationContext, $learningContext, 3) as $game) {
                $games[] = $game;

                if (count($games) >= $limit) {
                    return $games;
                }
            }
        }

        return $games;
    }

    protected function familyExpansionGames(
        array $existingCandidates,
        array $baseScores,
        array $correlationContext,
        array $learningContext,
        int $limit
    ): array {
        if ($limit <= 0) {
            return [];
        }

        $games = [];

        foreach (array_slice($existingCandidates, 0, 220) as $candidate) {
            $seed = $this->normalizeNumbers($candidate['dezenas'] ?? []);

            if (count($seed) !== 15) {
                continue;
            }

            foreach ($this->mutateSeedGame($seed, $baseScores, $correlationContext, $learningContext, 2) as $game) {
                $games[] = $game;

                if (count($games) >= $limit) {
                    return $games;
                }
            }
        }

        return $games;
    }

    protected function mutateSeedGame(
        array $seed,
        array $baseScores,
        array $correlationContext,
        array $learningContext,
        int $maxDepth
    ): array {
        $seed = $this->normalizeNumbers($seed);
        $games = [];
        $removalScores = [];

        foreach ($seed as $number) {
            $removalScores[$number] = $this->deterministicNumberWeight($number, array_values(array_diff($seed, [$number])), $baseScores, $correlationContext, 'hybrid');
        }

        asort($removalScores);
        $removePool = array_slice(array_keys($removalScores), 0, 8);
        $replacementPool = $this->rankedNumbers($baseScores, $seed, $correlationContext, 'hybrid');
        $replacementPool = array_slice(array_values(array_diff($replacementPool, $seed)), 0, 12);
        $maxMutations = max(1, (int) config('lottus_main_learning.candidate_generation.max_mutations_per_seed', 10));

        foreach ($removePool as $remove) {
            foreach ($replacementPool as $add) {
                $game = array_values(array_diff($seed, [(int) $remove]));
                $game[] = (int) $add;
                $game = $this->normalizeNumbers($game);

                if (count($game) === 15) {
                    $games[] = $game;
                }

                if (count($games) >= $maxMutations) {
                    return $games;
                }
            }
        }

        if ($maxDepth >= 2) {
            $removePairs = array_slice($this->pairsFromNumbers($removePool), 0, 18);
            $addPairs = array_slice($this->pairsFromNumbers($replacementPool), 0, 24);

            foreach ($removePairs as $removePair) {
                foreach ($addPairs as $addPair) {
                    $game = array_values(array_diff($seed, $removePair));
                    $game = array_merge($game, $addPair);
                    $game = $this->normalizeNumbers($game);

                    if (count($game) === 15) {
                        $games[] = $game;
                    }

                    if (count($games) >= ($maxMutations * 2)) {
                        return $games;
                    }
                }
            }
        }

        return $games;
    }

    protected function rankedNumbers(
        array $baseScores,
        array $selected,
        array $correlationContext,
        string $mode
    ): array {
        $pool = [];

        foreach (range(1, 25) as $number) {
            if (in_array($number, $selected, true)) {
                continue;
            }

            $pool[$number] = $this->deterministicNumberWeight($number, $selected, $baseScores, $correlationContext, $mode);
        }

        arsort($pool);

        return array_map('intval', array_keys($pool));
    }

    protected function pairsFromNumbers(array $numbers): array
    {
        $numbers = array_values(array_unique(array_map('intval', $numbers)));
        $pairs = [];

        for ($i = 0; $i < count($numbers) - 1; $i++) {
            for ($j = $i + 1; $j < count($numbers); $j++) {
                $pairs[] = [$numbers[$i], $numbers[$j]];
            }
        }

        return $pairs;
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
