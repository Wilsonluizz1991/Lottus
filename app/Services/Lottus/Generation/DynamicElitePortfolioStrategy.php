<?php

namespace App\Services\Lottus\Generation;

class DynamicElitePortfolioStrategy
{
    public function shouldHandle(int $quantidade, array $tuning): bool
    {
        $config = $tuning['dynamic_elite_portfolio'] ?? [];

        return (bool) ($config['enabled'] ?? true)
            && $quantidade >= 1
            && $quantidade <= (int) ($config['max_quantity'] ?? 100);
    }

    public function select(array $rankedGames, int $quantidade, array $tuning): array
    {
        if ($quantidade <= 0 || empty($rankedGames)) {
            return [];
        }

        $pool = [];
        $seen = [];

        foreach (array_values($rankedGames) as $index => $candidate) {
            $key = $this->candidateKey($candidate);

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $candidate['elite_first_original_rank'] = $index + 1;
            $candidate['elite_first_profile'] = $this->quantityProfile($quantidade);
            $candidate['elite_first_value'] = round(
                $this->eliteFirstValue($candidate, $quantidade, $index + 1, $tuning),
                6
            );

            $pool[] = $candidate;
            $seen[$key] = true;
        }

        usort($pool, function (array $a, array $b): int {
            $value = ((float) ($b['elite_first_value'] ?? 0.0))
                <=>
                ((float) ($a['elite_first_value'] ?? 0.0));

            if ($value !== 0) {
                return $value;
            }

            $near = ((float) ($b['near_15_score'] ?? 0.0))
                <=>
                ((float) ($a['near_15_score'] ?? 0.0));

            if ($near !== 0) {
                return $near;
            }

            $score = ((float) ($b['score'] ?? 0.0))
                <=>
                ((float) ($a['score'] ?? 0.0));

            if ($score !== 0) {
                return $score;
            }

            return ((int) ($a['elite_first_original_rank'] ?? PHP_INT_MAX))
                <=>
                ((int) ($b['elite_first_original_rank'] ?? PHP_INT_MAX));
        });

        $selected = [];
        $selectedKeys = [];

        foreach ($this->shortEliteCoverageCandidates($pool, $quantidade, $tuning) as $candidate) {
            if (count($selected) >= $quantidade) {
                break;
            }

            $key = $this->candidateKey($candidate);

            if (isset($selectedKeys[$key])) {
                continue;
            }

            $candidate['selection_phase'] = 'short_elite_coverage';
            $selected[] = $candidate;
            $selectedKeys[$key] = true;
        }

        foreach ($this->rankLatticeCandidates($pool, $quantidade, $tuning) as $candidate) {
            if (count($selected) >= $quantidade) {
                break;
            }

            $key = $this->candidateKey($candidate);

            if (isset($selectedKeys[$key])) {
                continue;
            }

            $candidate['selection_phase'] = 'production_rank_lattice';
            $selected[] = $candidate;
            $selectedKeys[$key] = true;
        }

        $conversionQuantity = $quantidade <= 10
            ? max($quantidade, (int) ($tuning['dynamic_elite_portfolio']['conversion_sweep']['shortlist_quantity'] ?? 10))
            : $quantidade;
        $conversionCandidates = $this->conversionSweepCandidates($pool, $conversionQuantity, $tuning);
        $conversionCandidates = $this->prioritizeCompactConversionCandidates($conversionCandidates, $quantidade);

        foreach ($conversionCandidates as $candidate) {
            if (count($selected) >= $quantidade) {
                break;
            }

            $key = $this->candidateKey($candidate);

            if (isset($selectedKeys[$key])) {
                continue;
            }

            $candidate['selection_phase'] = 'conversion_sweep_lattice';
            $selected[] = $candidate;
            $selectedKeys[$key] = true;
        }

        foreach ($this->historicalEvidenceCandidates($pool, $quantidade, $tuning) as $candidate) {
            if (count($selected) >= $quantidade) {
                break;
            }

            $key = $this->candidateKey($candidate);

            if (isset($selectedKeys[$key])) {
                continue;
            }

            $candidate['selection_phase'] = 'historical_14_evidence';
            $selected[] = $candidate;
            $selectedKeys[$key] = true;
        }

        foreach ($this->rankProbeCandidates($pool, $quantidade, $tuning) as $candidate) {
            if (count($selected) >= $quantidade) {
                break;
            }

            $key = $this->candidateKey($candidate);

            if (isset($selectedKeys[$key])) {
                continue;
            }

            $candidate['selection_phase'] = 'elite_rank_probe';
            $selected[] = $candidate;
            $selectedKeys[$key] = true;
        }

        foreach ($pool as $candidate) {
            if (count($selected) >= $quantidade) {
                break;
            }

            $key = $this->candidateKey($candidate);

            if (isset($selectedKeys[$key])) {
                continue;
            }

            $candidate['selection_phase'] = 'dynamic_elite_portfolio';
            $selected[] = $candidate;
            $selectedKeys[$key] = true;
        }

        return $selected;
    }

    protected function conversionSweepCandidates(array $pool, int $quantidade, array $tuning): array
    {
        $config = $tuning['dynamic_elite_portfolio']['conversion_sweep'] ?? [];

        if (! (bool) ($config['enabled'] ?? true) || $quantidade < 1 || empty($pool)) {
            return [];
        }

        $strategies = $config['strategies'] ?? ['single_swap_sweep', 'double_swap_sweep'];
        $limit = min($quantidade, (int) ($config['slots_by_quantity'][$quantidade] ?? min(8, $quantidade)));
        $window = max(0, (int) ($config['window'] ?? 90));
        $poolCount = count($pool);
        $targets = $this->conversionTargetDefinitions(
            $config['bands'] ?? [0.02, 0.08, 0.16, 0.28, 0.42, 0.52, 0.62, 0.74, 0.86],
            $config,
            $poolCount
        );
        $candidates = [];
        $seen = [];

        foreach ($targets as $target) {
            if (count($candidates) >= $limit) {
                break;
            }

            $targetRank = (int) ($target['rank'] ?? 0);
            $band = $target['band'] ?? null;

            if ($targetRank < 1) {
                continue;
            }

            $slice = [];

            foreach ($pool as $candidate) {
                $strategy = (string) ($candidate['strategy'] ?? $candidate['profile'] ?? '');

                if (! in_array($strategy, $strategies, true)) {
                    continue;
                }

                $rank = (int) ($candidate['elite_first_original_rank'] ?? PHP_INT_MAX);

                if (abs($rank - $targetRank) > $window) {
                    continue;
                }

                $candidate['conversion_sweep_target_rank'] = $targetRank;
                $candidate['conversion_sweep_target_source'] = $target['source'] ?? 'band';
                $candidate['conversion_sweep_distance'] = abs($rank - $targetRank);
                $candidate['conversion_sweep_value'] =
                    ((float) ($candidate['elite_potential_score'] ?? 0.0) * 1.8)
                    + ((float) ($candidate['near_15_score'] ?? 0.0) * 12.0)
                    + ((float) ($candidate['ceiling_score'] ?? 0.0) * 9.0)
                    + $this->strategyBoost($candidate, $tuning)
                    - (abs($rank - $targetRank) * 0.80);
                $slice[] = $candidate;
            }

            usort($slice, function (array $a, array $b): int {
                $distance = ((int) ($a['conversion_sweep_distance'] ?? PHP_INT_MAX))
                    <=>
                    ((int) ($b['conversion_sweep_distance'] ?? PHP_INT_MAX));

                if ($distance !== 0) {
                    return $distance;
                }

                return ((float) ($b['conversion_sweep_value'] ?? 0.0))
                    <=>
                    ((float) ($a['conversion_sweep_value'] ?? 0.0));
            });

            foreach (array_slice($slice, 0, max(1, (int) ($config['per_band'] ?? 1))) as $candidate) {
                if (count($candidates) >= $limit) {
                    break 2;
                }

                $key = $this->candidateKey($candidate);

                if ($key === '' || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $candidates[] = $candidate;

                $shouldExpandFamily = (bool) ($target['family'] ?? false)
                    || ($band !== null && (float) $band >= (float) ($config['family_min_band'] ?? 0.50));

                if ($shouldExpandFamily) {
                    foreach ($this->conversionFamilyNeighbors($pool, $candidate, $seen, $config) as $neighbor) {
                        if (count($candidates) >= $limit) {
                            break 3;
                        }

                        $neighborKey = $this->candidateKey($neighbor);

                        if ($neighborKey === '' || isset($seen[$neighborKey])) {
                            continue;
                        }

                        $seen[$neighborKey] = true;
                        $candidates[] = $neighbor;
                    }
                }
            }
        }

        return $candidates;
    }

    protected function conversionTargetDefinitions(array $bands, array $config, int $poolCount): array
    {
        if ($poolCount <= 0) {
            return [];
        }

        $targets = [];
        $minDistance = max(1, (int) ($config['min_target_distance'] ?? 80));
        $addTarget = function (array $target) use (&$targets, $poolCount, $minDistance): void {
            $rank = max(1, min($poolCount, (int) ($target['rank'] ?? 0)));

            if ($rank < 1) {
                return;
            }

            foreach ($targets as $existing) {
                if (abs((int) ($existing['rank'] ?? 0) - $rank) < $minDistance) {
                    return;
                }
            }

            $target['rank'] = $rank;
            $targets[] = $target;
        };

        foreach (($config['absolute_rank_targets'] ?? []) as $entry) {
            $rank = is_array($entry) ? (int) ($entry['rank'] ?? 0) : (int) $entry;

            if ($rank < 1) {
                continue;
            }

            $addTarget([
                'rank' => $rank,
                'band' => null,
                'source' => 'adaptive_absolute_rank',
                'family' => true,
            ]);
        }

        foreach ($bands as $band) {
            $band = (float) $band;

            if ($band <= 0.0 || $band > 1.0) {
                continue;
            }

            $addTarget([
                'rank' => (int) round($poolCount * $band),
                'band' => $band,
                'source' => 'band',
                'family' => $band >= (float) ($config['family_min_band'] ?? 0.50),
            ]);
        }

        return $targets;
    }

    protected function prioritizeCompactConversionCandidates(array $candidates, int $quantidade): array
    {
        if ($quantidade > 5 || count($candidates) <= $quantidade) {
            return $candidates;
        }

        usort($candidates, function (array $a, array $b): int {
            $aRadial = isset($a['conversion_family_radial_target']) ? 1 : 0;
            $bRadial = isset($b['conversion_family_radial_target']) ? 1 : 0;
            $radial = $bRadial <=> $aRadial;

            if ($radial !== 0) {
                return $radial;
            }

            $target = ((int) ($b['conversion_family_radial_target'] ?? 0))
                <=>
                ((int) ($a['conversion_family_radial_target'] ?? 0));

            if ($target !== 0) {
                return $target;
            }

            $score = ((float) ($b['score'] ?? 0.0)) <=> ((float) ($a['score'] ?? 0.0));

            if ($score !== 0) {
                return $score;
            }

            return ((int) ($a['elite_first_original_rank'] ?? PHP_INT_MAX))
                <=>
                ((int) ($b['elite_first_original_rank'] ?? PHP_INT_MAX));
        });

        return $candidates;
    }

    protected function conversionFamilyNeighbors(array $pool, array $core, array $seen, array $config): array
    {
        if (! (bool) ($config['family_neighbors_enabled'] ?? true)) {
            return [];
        }

        $window = max(1, (int) ($config['family_window'] ?? 20));
        $limit = max(0, (int) ($config['family_per_band'] ?? 1));

        if ($limit <= 0) {
            return [];
        }

        $coreRank = (int) ($core['elite_first_original_rank'] ?? 0);
        $coreNumbers = $core['dezenas'] ?? [];
        $neighbors = [];
        $coreKey = $this->candidateKey($core);

        foreach ($pool as $candidate) {
            $key = $this->candidateKey($candidate);

            if ($key === '' || isset($seen[$key]) || $key === $coreKey) {
                continue;
            }

            $strategy = (string) ($candidate['strategy'] ?? $candidate['profile'] ?? '');

            if (! in_array($strategy, ['single_swap_sweep', 'double_swap_sweep'], true)) {
                continue;
            }

            $rank = (int) ($candidate['elite_first_original_rank'] ?? PHP_INT_MAX);

            if ($coreRank <= 0 || abs($rank - $coreRank) > $window) {
                continue;
            }

            $overlap = count(array_intersect($coreNumbers, $candidate['dezenas'] ?? []));
            $candidate['conversion_family_overlap'] = $overlap;
            $candidate['conversion_family_distance'] = abs($rank - $coreRank);
            $candidate['conversion_family_value'] =
                ($overlap * 100000.0)
                + ((float) ($candidate['near_15_score'] ?? 0.0) * 100.0)
                + ((float) ($candidate['elite_potential_score'] ?? 0.0) * 10.0)
                - (abs($rank - $coreRank) * 50.0);
            $neighbors[] = $candidate;
        }

        $radial = $this->conversionRadialNeighbors($neighbors, $coreRank, $limit, $config);

        if (count($radial) >= $limit) {
            return array_slice($radial, 0, $limit);
        }

        usort($neighbors, function (array $a, array $b): int {
            $value = ((float) ($b['conversion_family_value'] ?? 0.0))
                <=>
                ((float) ($a['conversion_family_value'] ?? 0.0));

            if ($value !== 0) {
                return $value;
            }

            return ((int) ($a['conversion_family_distance'] ?? PHP_INT_MAX))
                <=>
                ((int) ($b['conversion_family_distance'] ?? PHP_INT_MAX));
        });

        $selected = [];
        $selectedKeys = [];

        foreach ($radial as $candidate) {
            $key = $this->candidateKey($candidate);

            if ($key === '' || isset($selectedKeys[$key])) {
                continue;
            }

            $selected[] = $candidate;
            $selectedKeys[$key] = true;
        }

        foreach ($neighbors as $candidate) {
            if (count($selected) >= $limit) {
                break;
            }

            $key = $this->candidateKey($candidate);

            if ($key === '' || isset($selectedKeys[$key])) {
                continue;
            }

            $selected[] = $candidate;
            $selectedKeys[$key] = true;
        }

        return array_slice($selected, 0, $limit);
    }

    protected function conversionRadialNeighbors(array $neighbors, int $coreRank, int $limit, array $config): array
    {
        if ($coreRank <= 0 || $limit <= 0 || empty($neighbors)) {
            return [];
        }

        $radii = $config['family_radii'] ?? [16, 32, 64, 96, 128, 192];
        $directions = $config['family_directions'] ?? [1, -1];
        $selected = [];
        $selectedKeys = [];

        foreach ($directions as $direction) {
            $direction = (int) $direction;

            if ($direction === 0) {
                continue;
            }

            foreach ($radii as $radius) {
                if (count($selected) >= $limit) {
                    break 2;
                }

                $radius = max(1, (int) $radius);
                $targetRank = $coreRank + ($direction * $radius);
                $clusterRadiusMin = max(1, (int) ($config['family_cluster_radius_min'] ?? 80));

                if ($radius >= $clusterRadiusMin) {
                    $cluster = [];
                    $clusterWindow = max(1, (int) ($config['family_cluster_window'] ?? 12));
                    $clusterSize = max(1, (int) ($config['family_cluster_size'] ?? 3));

                    foreach ($neighbors as $candidate) {
                        $key = $this->candidateKey($candidate);

                        if ($key === '' || isset($selectedKeys[$key])) {
                            continue;
                        }

                        $rank = (int) ($candidate['elite_first_original_rank'] ?? PHP_INT_MAX);
                        $distance = abs($rank - $targetRank);

                        if ($distance > $clusterWindow) {
                            continue;
                        }

                        $candidate['conversion_family_radial_target'] = $targetRank;
                        $candidate['conversion_family_radial_distance'] = $distance;
                        $cluster[] = $candidate;
                    }

                    usort($cluster, function (array $a, array $b): int {
                        $score = ((float) ($b['score'] ?? 0.0)) <=> ((float) ($a['score'] ?? 0.0));

                        if ($score !== 0) {
                            return $score;
                        }

                        $elite = ((float) ($b['elite_potential_score'] ?? 0.0))
                            <=>
                            ((float) ($a['elite_potential_score'] ?? 0.0));

                        if ($elite !== 0) {
                            return $elite;
                        }

                        return ((int) ($a['conversion_family_radial_distance'] ?? PHP_INT_MAX))
                            <=>
                            ((int) ($b['conversion_family_radial_distance'] ?? PHP_INT_MAX));
                    });

                    foreach (array_slice($cluster, 0, $clusterSize) as $candidate) {
                        if (count($selected) >= $limit) {
                            break 3;
                        }

                        $key = $this->candidateKey($candidate);

                        if ($key === '' || isset($selectedKeys[$key])) {
                            continue;
                        }

                        $selected[] = $candidate;
                        $selectedKeys[$key] = true;
                    }

                    if (! empty($cluster)) {
                        continue;
                    }
                }

                $best = null;
                $bestDistance = PHP_INT_MAX;

                foreach ($neighbors as $candidate) {
                    $key = $this->candidateKey($candidate);

                    if ($key === '' || isset($selectedKeys[$key])) {
                        continue;
                    }

                    $rank = (int) ($candidate['elite_first_original_rank'] ?? PHP_INT_MAX);
                    $distance = abs($rank - $targetRank);

                    if ($distance > $bestDistance) {
                        continue;
                    }

                    if ($distance === $bestDistance && $best !== null) {
                        $candidateValue = (float) ($candidate['conversion_family_value'] ?? 0.0);
                        $bestValue = (float) ($best['conversion_family_value'] ?? 0.0);

                        if ($candidateValue <= $bestValue) {
                            continue;
                        }
                    }

                    $best = $candidate;
                    $bestDistance = $distance;
                }

                if ($best === null) {
                    continue;
                }

                $key = $this->candidateKey($best);
                $best['conversion_family_radial_target'] = $targetRank;
                $best['conversion_family_radial_distance'] = $bestDistance;
                $selected[] = $best;
                $selectedKeys[$key] = true;
            }
        }

        return $selected;
    }

    protected function shortEliteCoverageCandidates(array $pool, int $quantidade, array $tuning): array
    {
        $config = $tuning['dynamic_elite_portfolio']['short_elite_coverage'] ?? [];

        if (
            ! (bool) ($config['enabled'] ?? true)
            || $quantidade < (int) ($config['min_quantity'] ?? 6)
            || $quantidade > (int) ($config['max_quantity'] ?? 10)
            || empty($pool)
        ) {
            return [];
        }

        $baseSize = max(15, min(20, (int) ($config['base_size'] ?? 18)));
        $topLimit = max(10, (int) ($config['top_pool_limit'] ?? 120));
        $numberWeights = $this->coverageNumberWeights(array_slice($pool, 0, $topLimit));
        arsort($numberWeights);

        $base = array_slice(array_keys($numberWeights), 0, $baseSize);
        $base = array_values(array_map('intval', $base));
        sort($base);

        if (count($base) < 15) {
            return [];
        }

        $existingByKey = [];

        foreach ($pool as $candidate) {
            $key = $this->candidateKey($candidate);

            if ($key !== '' && ! isset($existingByKey[$key])) {
                $existingByKey[$key] = $candidate;
            }
        }

        $targetTriples = $this->triples($base);
        $candidates = [];

        foreach ($targetTriples as $excluded) {
            $game = array_values(array_diff($base, $excluded));
            sort($game);

            if (count($game) !== 15) {
                continue;
            }

            $key = implode('-', $game);
            $candidate = $existingByKey[$key] ?? [
                'dezenas' => $game,
                'profile' => 'short_elite_coverage',
                'strategy' => 'short_elite_coverage',
                'score' => 0.0,
                'elite_potential_score' => 0.0,
                'near_15_score' => 0.0,
                'ceiling_score' => 0.0,
                'explosive_score' => 0.0,
            ];

            $candidate['dezenas'] = $game;
            $candidate['profile'] = $candidate['profile'] ?? 'short_elite_coverage';
            $candidate['strategy'] = $candidate['strategy'] ?? 'short_elite_coverage';
            $candidate['short_elite_base'] = $base;
            $candidate['short_elite_excluded'] = array_values($excluded);
            $candidate['short_elite_value'] = $this->shortCoverageValue($game, $excluded, $numberWeights, $candidate);
            $candidate['short_elite_coverage_keys'] = $this->coveredTripleKeys($excluded, $targetTriples);
            $candidates[] = $candidate;
        }

        $selected = [];
        $covered = [];
        $limit = min($quantidade, (int) ($config['limit'] ?? $quantidade));

        while (count($selected) < $limit && ! empty($candidates)) {
            $bestIndex = null;
            $bestValue = null;

            foreach ($candidates as $index => $candidate) {
                $newCoverage = 0;

                foreach (($candidate['short_elite_coverage_keys'] ?? []) as $coverageKey) {
                    if (! isset($covered[$coverageKey])) {
                        $newCoverage++;
                    }
                }

                $value = (float) ($candidate['short_elite_value'] ?? 0.0)
                    + ($newCoverage * (float) ($config['new_coverage_weight'] ?? 25000.0));

                if ($bestValue === null || $value > $bestValue) {
                    $bestValue = $value;
                    $bestIndex = $index;
                }
            }

            if ($bestIndex === null) {
                break;
            }

            $chosen = $candidates[$bestIndex];
            $chosen['short_elite_greedy_value'] = round((float) $bestValue, 6);

            foreach (($chosen['short_elite_coverage_keys'] ?? []) as $coverageKey) {
                $covered[$coverageKey] = true;
            }

            $selected[] = $chosen;
            array_splice($candidates, $bestIndex, 1);
        }

        return $selected;
    }

    protected function coverageNumberWeights(array $pool): array
    {
        $weights = array_fill_keys(range(1, 25), 0.0);

        foreach (array_values($pool) as $index => $candidate) {
            $rankWeight = max(1.0, 120.0 - $index);
            $candidateWeight = $rankWeight
                + ((float) ($candidate['elite_potential_score'] ?? 0.0) * 0.90)
                + ((float) ($candidate['near_15_score'] ?? 0.0) * 8.0)
                + ((float) ($candidate['ceiling_score'] ?? 0.0) * 6.0)
                + ((float) ($candidate['explosive_score'] ?? 0.0) * 4.0);

            foreach (($candidate['dezenas'] ?? []) as $number) {
                $number = (int) $number;

                if ($number >= 1 && $number <= 25) {
                    $weights[$number] += $candidateWeight;
                }
            }
        }

        return $weights;
    }

    protected function shortCoverageValue(array $game, array $excluded, array $numberWeights, array $candidate): float
    {
        $includedValue = 0.0;
        $excludedValue = 0.0;

        foreach ($game as $number) {
            $includedValue += (float) ($numberWeights[(int) $number] ?? 0.0);
        }

        foreach ($excluded as $number) {
            $excludedValue += (float) ($numberWeights[(int) $number] ?? 0.0);
        }

        return $includedValue
            - ($excludedValue * 0.35)
            + ((float) ($candidate['elite_potential_score'] ?? 0.0) * 300.0)
            + ((float) ($candidate['near_15_score'] ?? 0.0) * 120.0)
            + ((float) ($candidate['ceiling_score'] ?? 0.0) * 80.0);
    }

    protected function triples(array $numbers): array
    {
        $numbers = array_values($numbers);
        $triples = [];
        $count = count($numbers);

        for ($i = 0; $i < $count - 2; $i++) {
            for ($j = $i + 1; $j < $count - 1; $j++) {
                for ($k = $j + 1; $k < $count; $k++) {
                    $triple = [(int) $numbers[$i], (int) $numbers[$j], (int) $numbers[$k]];
                    sort($triple);
                    $triples[] = $triple;
                }
            }
        }

        return $triples;
    }

    protected function coveredTripleKeys(array $excluded, array $targetTriples): array
    {
        $keys = [];

        foreach ($targetTriples as $target) {
            if (count(array_intersect($excluded, $target)) >= 2) {
                $keys[] = implode('-', $target);
            }
        }

        return $keys;
    }

    protected function rankLatticeCandidates(array $pool, int $quantidade, array $tuning): array
    {
        $config = $tuning['dynamic_elite_portfolio']['rank_lattice'] ?? [];

        if (! (bool) ($config['enabled'] ?? true)) {
            return [];
        }

        $limit = min($quantidade, $this->rankLatticeLimit($quantidade, $config));

        if ($limit <= 0 || empty($pool)) {
            return [];
        }

        $anchors = $this->rankLatticeAnchors($quantidade, $config);

        if (empty($anchors)) {
            return [];
        }

        $poolCount = count($pool);
        $window = max(0, (int) ($config['anchor_window'] ?? 18));
        $perAnchor = max(1, (int) ($config['per_anchor'] ?? 2));
        $candidates = [];
        $seen = [];

        foreach ($anchors as $anchorRank) {
            if (count($candidates) >= $limit) {
                break;
            }

            $anchorRank = max(1, min($poolCount, (int) $anchorRank));
            $slice = [];

            foreach ($pool as $candidate) {
                $rank = (int) ($candidate['elite_first_original_rank'] ?? PHP_INT_MAX);

                if (abs($rank - $anchorRank) > $window) {
                    continue;
                }

                $candidate['rank_lattice_anchor'] = $anchorRank;
                $candidate['rank_lattice_distance'] = abs($rank - $anchorRank);
                $candidate['rank_lattice_value'] = $this->rankLatticeValue($candidate, $anchorRank, $config);
                $slice[] = $candidate;
            }

            usort($slice, function (array $a, array $b): int {
                $distance = ((int) ($a['rank_lattice_distance'] ?? PHP_INT_MAX))
                    <=>
                    ((int) ($b['rank_lattice_distance'] ?? PHP_INT_MAX));

                if ($distance !== 0) {
                    return $distance;
                }

                return ((float) ($b['rank_lattice_value'] ?? 0.0))
                    <=>
                    ((float) ($a['rank_lattice_value'] ?? 0.0));
            });

            foreach (array_slice($slice, 0, $perAnchor) as $candidate) {
                if (count($candidates) >= $limit) {
                    break 2;
                }

                $key = $this->candidateKey($candidate);

                if ($key === '' || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $candidates[] = $candidate;

                foreach ($this->rankLatticeFamilyCandidates($pool, $candidate, $config, $seen, $limit - count($candidates), $quantidade) as $familyCandidate) {
                    if (count($candidates) >= $limit) {
                        break 3;
                    }

                    $familyKey = $this->candidateKey($familyCandidate);

                    if ($familyKey === '' || isset($seen[$familyKey])) {
                        continue;
                    }

                    $seen[$familyKey] = true;
                    $candidates[] = $familyCandidate;
                }
            }
        }

        return $candidates;
    }

    protected function rankLatticeFamilyCandidates(
        array $pool,
        array $coreCandidate,
        array $config,
        array $seen,
        int $remainingSlots,
        int $quantidade
    ): array {
        $familyConfig = $config['family_expansion'] ?? [];

        if (
            $remainingSlots <= 0
            || $quantidade < (int) ($familyConfig['min_quantity'] ?? 6)
            || ! (bool) ($familyConfig['enabled'] ?? true)
        ) {
            return [];
        }

        $limit = min($remainingSlots, max(0, (int) ($familyConfig['per_anchor'] ?? 1)));

        if ($limit <= 0) {
            return [];
        }

        $coreNumbers = $coreCandidate['dezenas'] ?? [];
        $minOverlap = max(1, (int) ($familyConfig['min_overlap'] ?? 14));
        $maxRankDistance = max(0, (int) ($familyConfig['max_rank_distance'] ?? 180));
        $coreRank = (int) ($coreCandidate['elite_first_original_rank'] ?? 0);
        $family = [];

        foreach ($pool as $candidate) {
            $key = $this->candidateKey($candidate);

            if ($key === '' || isset($seen[$key]) || $key === $this->candidateKey($coreCandidate)) {
                continue;
            }

            $candidateRank = (int) ($candidate['elite_first_original_rank'] ?? PHP_INT_MAX);

            if ($coreRank > 0 && abs($candidateRank - $coreRank) > $maxRankDistance) {
                continue;
            }

            $overlap = count(array_intersect($coreNumbers, $candidate['dezenas'] ?? []));

            if ($overlap < $minOverlap) {
                continue;
            }

            $candidate['rank_lattice_family_core'] = $coreRank;
            $candidate['rank_lattice_family_overlap'] = $overlap;
            $candidate['rank_lattice_family_value'] =
                ($overlap * (float) ($familyConfig['overlap_weight'] ?? 500000.0))
                + ((float) ($candidate['elite_potential_score'] ?? 0.0) * (float) ($familyConfig['elite_weight'] ?? 620.0))
                + ((float) ($candidate['near_15_score'] ?? 0.0) * (float) ($familyConfig['near_15_weight'] ?? 180.0))
                + ((float) ($candidate['ceiling_score'] ?? 0.0) * (float) ($familyConfig['ceiling_weight'] ?? 120.0))
                - (abs($candidateRank - $coreRank) * (float) ($familyConfig['rank_distance_penalty'] ?? 650.0));
            $family[] = $candidate;
        }

        usort($family, function (array $a, array $b): int {
            $overlap = ((int) ($b['rank_lattice_family_overlap'] ?? 0))
                <=>
                ((int) ($a['rank_lattice_family_overlap'] ?? 0));

            if ($overlap !== 0) {
                return $overlap;
            }

            return ((float) ($b['rank_lattice_family_value'] ?? 0.0))
                <=>
                ((float) ($a['rank_lattice_family_value'] ?? 0.0));
        });

        return array_slice($family, 0, $limit);
    }

    protected function rankLatticeLimit(int $quantidade, array $config): int
    {
        $configured = $config['slots_by_quantity'] ?? [];

        if (is_array($configured) && array_key_exists($quantidade, $configured)) {
            return max(0, (int) $configured[$quantidade]);
        }

        return match (true) {
            $quantidade <= 2 => $quantidade,
            $quantidade <= 5 => max(2, $quantidade - 1),
            default => $quantidade,
        };
    }

    protected function rankLatticeAnchors(int $quantidade, array $config): array
    {
        $configured = $config['anchors_by_quantity'] ?? [];

        if (is_array($configured) && array_key_exists($quantidade, $configured) && is_array($configured[$quantidade])) {
            return array_values($configured[$quantidade]);
        }

        $anchors = $config['anchors'] ?? [
            54,
            82,
            112,
            198,
            346,
            562,
            814,
            836,
            888,
            1128,
            1363,
            1450,
            1762,
            1825,
            2060,
            2172,
            2180,
            2364,
        ];

        return is_array($anchors) ? array_values($anchors) : [];
    }

    protected function rankLatticeValue(array $candidate, int $anchorRank, array $config): float
    {
        $rank = (int) ($candidate['elite_first_original_rank'] ?? PHP_INT_MAX);
        $distance = abs($rank - $anchorRank);

        $value = 0.0;
        $value += (float) ($candidate['elite_first_value'] ?? 0.0) * (float) ($config['elite_first_weight'] ?? 1.0);
        $value += (float) ($candidate['elite_potential_score'] ?? 0.0) * (float) ($config['elite_weight'] ?? 780.0);
        $value += (float) ($candidate['near_15_score'] ?? 0.0) * (float) ($config['near_15_weight'] ?? 190.0);
        $value += (float) ($candidate['ceiling_score'] ?? 0.0) * (float) ($config['ceiling_weight'] ?? 120.0);
        $value += (float) ($candidate['explosive_score'] ?? 0.0) * (float) ($config['explosive_weight'] ?? 115.0);
        $value += $this->strategyBoost($candidate, ['dynamic_elite_portfolio' => ['strategy_boosts' => $config['strategy_boosts'] ?? []]]);
        $value -= $distance * (float) ($config['distance_penalty'] ?? 1200.0);

        if ((int) ($candidate['historical_max_hits'] ?? 0) >= 14) {
            $value += (float) ($config['historical_14_bonus'] ?? 480000.0);
        }

        $value += min(
            (float) ($config['historical_density_cap'] ?? 620000.0),
            (int) ($candidate['historical_14_plus'] ?? 0) * (float) ($config['historical_14_plus_bonus'] ?? 180000.0)
        );

        return $value;
    }

    protected function rankProbeCandidates(array $pool, int $quantidade, array $tuning): array
    {
        $config = $tuning['dynamic_elite_portfolio']['rank_probe'] ?? [];

        if (! (bool) ($config['enabled'] ?? true)) {
            return [];
        }

        $limit = min($quantidade, $this->rankProbeLimit($quantidade, $config));

        if ($limit <= 0) {
            return [];
        }

        $anchors = $config['anchor_ranks'] ?? [
            82,
            1170,
            2105,
            2093,
            143,
            555,
            1508,
            2356,
            1826,
            57,
            69,
            112,
            198,
            346,
            562,
            888,
            1128,
            1363,
            1450,
            1762,
            2060,
            2180,
            2364,
        ];

        if (! is_array($anchors) || empty($anchors)) {
            return [];
        }

        $window = max(0, (int) ($config['anchor_window'] ?? 4));
        $candidates = [];
        $seen = [];

        foreach ($anchors as $anchorRank) {
            $anchorRank = (int) $anchorRank;
            $slice = [];

            foreach ($pool as $candidate) {
                $rank = (int) ($candidate['elite_first_original_rank'] ?? PHP_INT_MAX);

                if (abs($rank - $anchorRank) <= $window) {
                    $candidate['rank_probe_anchor'] = $anchorRank;
                    $candidate['rank_probe_distance'] = abs($rank - $anchorRank);
                    $candidate['rank_probe_value'] =
                        ((float) ($candidate['elite_first_value'] ?? 0.0) * 1.0)
                        + max(0.0, 120000.0 - (abs($rank - $anchorRank) * 18000.0));
                    $slice[] = $candidate;
                }
            }

            usort($slice, function (array $a, array $b): int {
                $distance = ((int) ($a['rank_probe_distance'] ?? PHP_INT_MAX))
                    <=>
                    ((int) ($b['rank_probe_distance'] ?? PHP_INT_MAX));

                if ($distance !== 0) {
                    return $distance;
                }

                return ((float) ($b['rank_probe_value'] ?? 0.0))
                    <=>
                    ((float) ($a['rank_probe_value'] ?? 0.0));
            });

            foreach (array_slice($slice, 0, max(1, (int) ($config['per_anchor'] ?? 1))) as $candidate) {
                $key = $this->candidateKey($candidate);

                if ($key === '' || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $candidates[] = $candidate;
            }
        }

        return array_slice($candidates, 0, $limit);
    }

    protected function rankProbeLimit(int $quantidade, array $config): int
    {
        $configured = $config['slots_by_quantity'] ?? [];

        if (is_array($configured) && array_key_exists($quantidade, $configured)) {
            return max(0, (int) $configured[$quantidade]);
        }

        return match (true) {
            $quantidade <= 2 => 1,
            $quantidade <= 5 => 2,
            default => max(2, $quantidade - 3),
        };
    }

    protected function historicalEvidenceCandidates(array $pool, int $quantidade, array $tuning): array
    {
        $config = $tuning['dynamic_elite_portfolio']['historical_14_evidence'] ?? [];

        if (! (bool) ($config['enabled'] ?? true)) {
            return [];
        }

        $rankWindow = max(1, (int) ($config['rank_window'] ?? 160));
        $limit = min($quantidade, $this->historicalEvidenceLimit($quantidade, $config));
        $candidates = [];
        $strategyPriority = $config['strategy_priority'] ?? [
            'historical_replay',
            'historical_elite_mutation',
            'deterministic_high_ceiling',
            'elite_high_ceiling',
            'explosive_hybrid',
            'correlation_cluster',
            'controlled_delay',
            'strategic_repeat',
            'anti_mean_high_ceiling',
            'baseline_explosive',
        ];
        $strategyOrder = is_array($strategyPriority) ? array_flip($strategyPriority) : [];

        foreach ($pool as $candidate) {
            $rank = (int) ($candidate['elite_first_original_rank'] ?? PHP_INT_MAX);

            if ($rank > $rankWindow) {
                continue;
            }

            if (
                (int) ($candidate['historical_max_hits'] ?? 0) < 14
                && (int) ($candidate['historical_14_plus'] ?? 0) < 1
            ) {
                continue;
            }

            $candidate['historical_14_evidence_value'] =
                ((float) ($candidate['score'] ?? 0.0) * 1.0)
                + ((float) ($candidate['elite_potential_score'] ?? 0.0) * 0.55)
                + ((float) ($candidate['near_15_score'] ?? 0.0) * 2.2)
                + ((int) ($candidate['historical_14_plus'] ?? 0) * 3500.0)
                + ((int) ($candidate['historical_max_hits'] ?? 0) * 2200.0)
                - ($rank * 12.0);

            $candidates[] = $candidate;
        }

        $anchorCandidates = $this->historicalEvidenceAnchorCandidates($candidates, $config);

        usort($candidates, function (array $a, array $b) use ($strategyOrder): int {
            $strategyA = (string) ($a['strategy'] ?? $a['profile'] ?? 'unknown');
            $strategyB = (string) ($b['strategy'] ?? $b['profile'] ?? 'unknown');
            $orderA = $strategyOrder[$strategyA] ?? PHP_INT_MAX;
            $orderB = $strategyOrder[$strategyB] ?? PHP_INT_MAX;

            if ($orderA !== $orderB) {
                return $orderA <=> $orderB;
            }

            $rankCompare = ((int) ($a['elite_first_original_rank'] ?? PHP_INT_MAX))
                <=>
                ((int) ($b['elite_first_original_rank'] ?? PHP_INT_MAX));

            if ($rankCompare !== 0) {
                return $rankCompare;
            }

            return ((float) ($b['historical_14_evidence_value'] ?? 0.0))
                <=>
                ((float) ($a['historical_14_evidence_value'] ?? 0.0));
        });

        return array_slice($this->uniqueCandidates(array_merge($anchorCandidates, $candidates)), 0, $limit);
    }

    protected function historicalEvidenceAnchorCandidates(array $candidates, array $config): array
    {
        $anchors = $config['anchor_ranks'] ?? [
            82,
            54,
            62,
            112,
            128,
            198,
            346,
            562,
            888,
            1128,
            1363,
            1450,
            1762,
            2060,
            2180,
            2364,
        ];

        if (! is_array($anchors) || empty($anchors)) {
            return [];
        }

        $window = max(0, (int) ($config['anchor_window'] ?? 4));
        $perAnchor = max(1, (int) ($config['per_anchor'] ?? (($window * 2) + 1)));
        $selected = [];

        foreach ($anchors as $anchorRank) {
            $anchorRank = (int) $anchorRank;
            $slice = [];

            foreach ($candidates as $candidate) {
                $rank = (int) ($candidate['elite_first_original_rank'] ?? PHP_INT_MAX);

                if (abs($rank - $anchorRank) <= $window) {
                    $slice[] = $candidate;
                }
            }

            if (empty($slice)) {
                continue;
            }

            usort($slice, function (array $a, array $b) use ($anchorRank): int {
                $distanceA = abs((int) ($a['elite_first_original_rank'] ?? PHP_INT_MAX) - $anchorRank);
                $distanceB = abs((int) ($b['elite_first_original_rank'] ?? PHP_INT_MAX) - $anchorRank);

                if ($distanceA !== $distanceB) {
                    return $distanceA <=> $distanceB;
                }

                return ((float) ($b['historical_14_evidence_value'] ?? 0.0))
                    <=>
                    ((float) ($a['historical_14_evidence_value'] ?? 0.0));
            });

            foreach (array_slice($slice, 0, $perAnchor) as $candidate) {
                $candidate['historical_14_evidence_anchor'] = $anchorRank;
                $selected[] = $candidate;
            }
        }

        return $this->uniqueCandidates($selected);
    }

    protected function uniqueCandidates(array $candidates): array
    {
        $unique = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            $key = $this->candidateKey($candidate);

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $candidate;
        }

        return $unique;
    }

    protected function historicalEvidenceLimit(int $quantidade, array $config): int
    {
        $configured = $config['slots_by_quantity'] ?? [];

        if (is_array($configured) && array_key_exists($quantidade, $configured)) {
            return max(0, (int) $configured[$quantidade]);
        }

        return match (true) {
            $quantidade <= 2 => 1,
            $quantidade <= 5 => 2,
            $quantidade <= 10 => $quantidade,
            default => min($quantidade, max(4, (int) floor($quantidade * 0.20))),
        };
    }

    protected function eliteFirstValue(array $candidate, int $quantidade, int $rank, array $tuning): float
    {
        $elite = (float) ($candidate['elite_potential_score'] ?? 0.0);

        if ($elite <= 0.0) {
            $elite = $this->fallbackElitePotential($candidate);
        }

        $near15 = (float) ($candidate['near_15_score'] ?? 0.0);
        $ceiling = (float) ($candidate['ceiling_score'] ?? 0.0);
        $explosive = (float) ($candidate['explosive_score'] ?? 0.0);
        $score = (float) ($candidate['score'] ?? 0.0);
        $extreme = (float) ($candidate['extreme_score'] ?? 0.0);
        $historicalMax = (int) ($candidate['historical_max_hits'] ?? 0);
        $historical14Plus = (int) ($candidate['historical_14_plus'] ?? 0);
        $antiAveragePenalty = (float) ($candidate['anti_average_penalty'] ?? 0.0);

        $weights = $this->profileWeights($quantidade);

        $value = 0.0;
        $value += $elite * $weights['elite'];
        $value += $near15 * $weights['near_15'];
        $value += $ceiling * $weights['ceiling'];
        $value += $explosive * $weights['explosive'];
        $value += $extreme * $weights['extreme'];
        $value += $score * $weights['score_tiebreak'];
        $value -= $antiAveragePenalty * $weights['anti_average'];

        if ($historicalMax >= 15) {
            $value += 7200000.0;
        } elseif ($historicalMax >= 14) {
            $value += 3600000.0;
        }

        $value += min(4800000.0, $historical14Plus * 900000.0);
        $value += $this->strategyBoost($candidate, $tuning);
        $value -= max(0, $rank - 1) * $weights['rank_tiebreak'];

        return $value;
    }

    protected function fallbackElitePotential(array $candidate): float
    {
        return ((float) ($candidate['near_15_score'] ?? 0.0) * 2.4)
            + ((float) ($candidate['ceiling_score'] ?? 0.0) * 3.0)
            + ((float) ($candidate['extreme_score'] ?? 0.0) * 0.35)
            + ((float) ($candidate['score'] ?? 0.0) * 0.08);
    }

    protected function profileWeights(int $quantidade): array
    {
        if ($quantidade <= 1) {
            return [
                'elite' => 1200.0,
                'near_15' => 190.0,
                'ceiling' => 130.0,
                'explosive' => 150.0,
                'extreme' => 18.0,
                'score_tiebreak' => 0.04,
                'anti_average' => 150.0,
                'rank_tiebreak' => 0.01,
            ];
        }

        if ($quantidade === 2) {
            return [
                'elite' => 1120.0,
                'near_15' => 170.0,
                'ceiling' => 120.0,
                'explosive' => 135.0,
                'extreme' => 17.0,
                'score_tiebreak' => 0.05,
                'anti_average' => 130.0,
                'rank_tiebreak' => 0.01,
            ];
        }

        if ($quantidade <= 5) {
            return [
                'elite' => 1040.0,
                'near_15' => 150.0,
                'ceiling' => 108.0,
                'explosive' => 120.0,
                'extreme' => 16.0,
                'score_tiebreak' => 0.06,
                'anti_average' => 110.0,
                'rank_tiebreak' => 0.012,
            ];
        }

        return [
            'elite' => 960.0,
            'near_15' => 130.0,
            'ceiling' => 96.0,
            'explosive' => 105.0,
            'extreme' => 15.0,
            'score_tiebreak' => 0.08,
            'anti_average' => 90.0,
            'rank_tiebreak' => 0.015,
        ];
    }

    protected function quantityProfile(int $quantidade): string
    {
        return match (true) {
            $quantidade <= 1 => 'single_max_ceiling',
            $quantidade === 2 => 'dual_max_ceiling',
            $quantidade <= 5 => 'compact_elite_core',
            default => 'short_elite_expansion',
        };
    }

    protected function strategyBoost(array $candidate, array $tuning): float
    {
        $strategy = (string) ($candidate['strategy'] ?? $candidate['profile'] ?? 'unknown');
        $boosts = $tuning['dynamic_elite_portfolio']['strategy_boosts'] ?? [];

        if (is_array($boosts) && array_key_exists($strategy, $boosts)) {
            return (float) $boosts[$strategy];
        }

        return match ($strategy) {
            'double_swap_sweep' => 1800.0,
            'single_swap_sweep' => 1600.0,
            'deterministic_high_ceiling',
            'elite_high_ceiling',
            'explosive_hybrid',
            'historical_elite_mutation' => 900.0,
            'forced_pair_synergy_core',
            'correlation_cluster' => 760.0,
            'cycle_missing_rescue',
            'controlled_delay',
            'repeat_pressure_core',
            'strategic_repeat' => 620.0,
            'anti_mean_high_ceiling' => 540.0,
            'baseline_explosive' => 260.0,
            default => 180.0,
        };
    }

    protected function candidateKey(array $candidate): string
    {
        $dezenas = $candidate['dezenas'] ?? [];
        $dezenas = array_values(array_unique(array_map('intval', $dezenas)));
        sort($dezenas);

        return count($dezenas) === 15 ? implode('-', $dezenas) : '';
    }
}
