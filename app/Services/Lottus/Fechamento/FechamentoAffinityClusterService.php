<?php

namespace App\Services\Lottus\Fechamento;

class FechamentoAffinityClusterService
{
    public function buildClusterBases(
        array $historicalDraws,
        array $profiles,
        int $quantidadeDezenas,
        array $patternContext = [],
        int $maxBases = 8
    ): array {
        $historicalDraws = array_values(array_filter(
            array_map(fn ($draw) => $this->normalizeNumbers((array) $draw), $historicalDraws),
            fn ($draw) => count($draw) === 15
        ));

        if (count($historicalDraws) < 20 || $quantidadeDezenas < 16 || $quantidadeDezenas > 20) {
            return [];
        }

        $recentDraws = array_slice($historicalDraws, -80);
        $shortDraws = array_slice($historicalDraws, -25);

        $pairScores = $this->pairScores($recentDraws, $shortDraws);
        $tripletScores = $this->tripletScores($recentDraws, $shortDraws);
        $anchorNumbers = $this->rankAnchorNumbers($profiles, $pairScores, $tripletScores);

        $clusters = [];

        foreach (array_slice($anchorNumbers, 0, 12) as $anchor) {
            $cluster = $this->expandCluster(
                anchor: (int) $anchor,
                pairScores: $pairScores,
                tripletScores: $tripletScores,
                profiles: $profiles,
                targetSize: $this->clusterTargetSize($quantidadeDezenas),
                patternContext: $patternContext
            );

            if (count($cluster) < 4) {
                continue;
            }

            $clusters[] = [
                'name' => 'anchor_' . $anchor,
                'numbers' => $cluster,
                'strength' => $this->clusterStrength($cluster, $pairScores, $tripletScores, $profiles),
            ];
        }

        foreach ($this->topTripletBlocks($tripletScores, $profiles) as $index => $tripletBlock) {
            $cluster = $this->expandClusterFromSeed(
                seed: $tripletBlock['numbers'],
                pairScores: $pairScores,
                tripletScores: $tripletScores,
                profiles: $profiles,
                targetSize: $this->clusterTargetSize($quantidadeDezenas),
                patternContext: $patternContext
            );

            if (count($cluster) < 4) {
                continue;
            }

            $clusters[] = [
                'name' => 'triplet_' . ($index + 1),
                'numbers' => $cluster,
                'strength' => $this->clusterStrength($cluster, $pairScores, $tripletScores, $profiles),
            ];
        }

        $clusters = $this->uniqueClusters($clusters);

        usort($clusters, fn (array $a, array $b) => ($b['strength'] ?? 0) <=> ($a['strength'] ?? 0));

        $bases = [];

        foreach (array_slice($clusters, 0, $maxBases) as $cluster) {
            $base = $this->buildBaseFromCluster(
                cluster: $cluster['numbers'],
                profiles: $profiles,
                pairScores: $pairScores,
                tripletScores: $tripletScores,
                quantidadeDezenas: $quantidadeDezenas,
                patternContext: $patternContext
            );

            if (count($base) !== $quantidadeDezenas) {
                continue;
            }

            $bases[] = [
                'strategy' => 'affinity_cluster_' . $cluster['name'],
                'numbers' => $base,
                'cluster' => $cluster['numbers'],
                'cluster_strength' => $cluster['strength'],
            ];
        }

        return $this->uniqueBases($bases);
    }

    protected function buildBaseFromCluster(
        array $cluster,
        array $profiles,
        array $pairScores,
        array $tripletScores,
        int $quantidadeDezenas,
        array $patternContext
    ): array {
        $selected = $this->normalizeNumbers($cluster);

        $ranked = [];

        foreach (range(1, 25) as $number) {
            if (in_array($number, $selected, true)) {
                continue;
            }

            $profile = $profiles[$number] ?? [];

            $ranked[] = [
                'number' => $number,
                'score' => $this->candidateClusterExpansionScore(
                    number: $number,
                    selected: $selected,
                    pairScores: $pairScores,
                    tripletScores: $tripletScores,
                    profile: $profile,
                    patternContext: $patternContext
                ),
            ];
        }

        usort($ranked, function (array $a, array $b): int {
            if (($a['score'] ?? 0) === ($b['score'] ?? 0)) {
                return ((int) $a['number']) <=> ((int) $b['number']);
            }

            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });

        foreach ($ranked as $candidate) {
            if (count($selected) >= $quantidadeDezenas) {
                break;
            }

            $number = (int) $candidate['number'];

            $test = $this->normalizeNumbers(array_merge($selected, [$number]));

            if (! $this->passesStructuralTolerance($test, $quantidadeDezenas)) {
                continue;
            }

            $selected = $test;
        }

        if (count($selected) < $quantidadeDezenas) {
            foreach ($ranked as $candidate) {
                if (count($selected) >= $quantidadeDezenas) {
                    break;
                }

                $number = (int) $candidate['number'];

                if (! in_array($number, $selected, true)) {
                    $selected[] = $number;
                    $selected = $this->normalizeNumbers($selected);
                }
            }
        }

        return array_slice($selected, 0, $quantidadeDezenas);
    }

    protected function expandCluster(
        int $anchor,
        array $pairScores,
        array $tripletScores,
        array $profiles,
        int $targetSize,
        array $patternContext
    ): array {
        return $this->expandClusterFromSeed(
            seed: [$anchor],
            pairScores: $pairScores,
            tripletScores: $tripletScores,
            profiles: $profiles,
            targetSize: $targetSize,
            patternContext: $patternContext
        );
    }

    protected function expandClusterFromSeed(
        array $seed,
        array $pairScores,
        array $tripletScores,
        array $profiles,
        int $targetSize,
        array $patternContext
    ): array {
        $cluster = $this->normalizeNumbers($seed);

        while (count($cluster) < $targetSize) {
            $bestNumber = null;
            $bestScore = null;

            foreach (range(1, 25) as $number) {
                if (in_array($number, $cluster, true)) {
                    continue;
                }

                $score = $this->candidateClusterExpansionScore(
                    number: $number,
                    selected: $cluster,
                    pairScores: $pairScores,
                    tripletScores: $tripletScores,
                    profile: $profiles[$number] ?? [],
                    patternContext: $patternContext
                );

                if ($bestScore === null || $score > $bestScore) {
                    $bestScore = $score;
                    $bestNumber = $number;
                }
            }

            if ($bestNumber === null) {
                break;
            }

            $cluster[] = $bestNumber;
            $cluster = $this->normalizeNumbers($cluster);
        }

        return $cluster;
    }

    protected function candidateClusterExpansionScore(
        int $number,
        array $selected,
        array $pairScores,
        array $tripletScores,
        array $profile,
        array $patternContext
    ): float {
        $pairAffinity = 0.0;
        $pairCount = 0;

        foreach ($selected as $selectedNumber) {
            $key = $this->pairKey($number, (int) $selectedNumber);
            $pairAffinity += (float) ($pairScores[$key] ?? 0.0);
            $pairCount++;
        }

        $pairAffinity = $pairCount > 0 ? $pairAffinity / $pairCount : 0.0;

        $tripletAffinity = 0.0;
        $tripletCount = 0;
        $selectedCount = count($selected);

        for ($i = 0; $i < $selectedCount; $i++) {
            for ($j = $i + 1; $j < $selectedCount; $j++) {
                $key = $this->tripletKey($number, (int) $selected[$i], (int) $selected[$j]);
                $tripletAffinity += (float) ($tripletScores[$key] ?? 0.0);
                $tripletCount++;
            }
        }

        $tripletAffinity = $tripletCount > 0 ? $tripletAffinity / $tripletCount : 0.0;

        $maturity = (float) ($profile['maturity'] ?? 0.0);
        $returnPressure = (float) ($profile['return_pressure'] ?? 0.0);
        $persistence = (float) ($profile['persistence'] ?? 0.0);
        $antiBias = (float) ($profile['anti_bias'] ?? 0.0);

        $regime = (string) ($patternContext['regime'] ?? 'elite_convergence');

        $regimeBoost = match ($regime) {
            'cycle_return' => $returnPressure * 0.12,
            'high_repetition', 'hot_persistence' => $persistence * 0.12,
            'volatile_transition' => $antiBias * 0.10,
            default => $maturity * 0.08,
        };

        return
            ($pairAffinity * 0.38) +
            ($tripletAffinity * 0.28) +
            ($maturity * 0.14) +
            ($returnPressure * 0.10) +
            ($persistence * 0.06) +
            ($antiBias * 0.04) +
            $regimeBoost;
    }

    protected function pairScores(array $recentDraws, array $shortDraws): array
    {
        $scores = [];

        foreach ($recentDraws as $draw) {
            $draw = $this->normalizeNumbers($draw);

            for ($i = 0; $i < count($draw); $i++) {
                for ($j = $i + 1; $j < count($draw); $j++) {
                    $key = $this->pairKey($draw[$i], $draw[$j]);
                    $scores[$key] = ($scores[$key] ?? 0.0) + 1.0;
                }
            }
        }

        foreach ($shortDraws as $draw) {
            $draw = $this->normalizeNumbers($draw);

            for ($i = 0; $i < count($draw); $i++) {
                for ($j = $i + 1; $j < count($draw); $j++) {
                    $key = $this->pairKey($draw[$i], $draw[$j]);
                    $scores[$key] = ($scores[$key] ?? 0.0) + 0.75;
                }
            }
        }

        return $this->normalizeMap($scores);
    }

    protected function tripletScores(array $recentDraws, array $shortDraws): array
    {
        $scores = [];

        foreach ($recentDraws as $draw) {
            $draw = $this->normalizeNumbers($draw);

            for ($i = 0; $i < count($draw); $i++) {
                for ($j = $i + 1; $j < count($draw); $j++) {
                    for ($k = $j + 1; $k < count($draw); $k++) {
                        $key = $this->tripletKey($draw[$i], $draw[$j], $draw[$k]);
                        $scores[$key] = ($scores[$key] ?? 0.0) + 1.0;
                    }
                }
            }
        }

        foreach ($shortDraws as $draw) {
            $draw = $this->normalizeNumbers($draw);

            for ($i = 0; $i < count($draw); $i++) {
                for ($j = $i + 1; $j < count($draw); $j++) {
                    for ($k = $j + 1; $k < count($draw); $k++) {
                        $key = $this->tripletKey($draw[$i], $draw[$j], $draw[$k]);
                        $scores[$key] = ($scores[$key] ?? 0.0) + 0.85;
                    }
                }
            }
        }

        return $this->normalizeMap($scores);
    }

    protected function rankAnchorNumbers(array $profiles, array $pairScores, array $tripletScores): array
    {
        $ranked = [];

        foreach (range(1, 25) as $number) {
            $pairStrength = 0.0;
            $pairCount = 0;

            foreach (range(1, 25) as $other) {
                if ($other === $number) {
                    continue;
                }

                $pairStrength += (float) ($pairScores[$this->pairKey($number, $other)] ?? 0.0);
                $pairCount++;
            }

            $pairStrength = $pairCount > 0 ? $pairStrength / $pairCount : 0.0;

            $profile = $profiles[$number] ?? [];

            $ranked[] = [
                'number' => $number,
                'score' =>
                    ($pairStrength * 0.42) +
                    ((float) ($profile['maturity'] ?? 0.0) * 0.22) +
                    ((float) ($profile['affinity'] ?? 0.0) * 0.18) +
                    ((float) ($profile['return_pressure'] ?? 0.0) * 0.12) +
                    ((float) ($profile['persistence'] ?? 0.0) * 0.06),
            ];
        }

        usort($ranked, fn (array $a, array $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        return array_map(fn (array $item) => (int) $item['number'], $ranked);
    }

    protected function topTripletBlocks(array $tripletScores, array $profiles): array
    {
        arsort($tripletScores);

        $blocks = [];

        foreach (array_slice($tripletScores, 0, 30, true) as $key => $score) {
            $numbers = array_map('intval', explode('-', $key));

            $profileScore = 0.0;

            foreach ($numbers as $number) {
                $profileScore +=
                    ((float) ($profiles[$number]['maturity'] ?? 0.0) * 0.35) +
                    ((float) ($profiles[$number]['affinity'] ?? 0.0) * 0.35) +
                    ((float) ($profiles[$number]['return_pressure'] ?? 0.0) * 0.20) +
                    ((float) ($profiles[$number]['persistence'] ?? 0.0) * 0.10);
            }

            $blocks[] = [
                'numbers' => $this->normalizeNumbers($numbers),
                'score' => ((float) $score * 0.65) + (($profileScore / 3) * 0.35),
            ];
        }

        usort($blocks, fn (array $a, array $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        return array_slice($blocks, 0, 10);
    }

    protected function clusterStrength(array $cluster, array $pairScores, array $tripletScores, array $profiles): float
    {
        $pairTotal = 0.0;
        $pairCount = 0;
        $tripletTotal = 0.0;
        $tripletCount = 0;
        $profileTotal = 0.0;

        $cluster = $this->normalizeNumbers($cluster);

        for ($i = 0; $i < count($cluster); $i++) {
            $profileTotal += (float) ($profiles[$cluster[$i]]['maturity'] ?? 0.0);

            for ($j = $i + 1; $j < count($cluster); $j++) {
                $pairTotal += (float) ($pairScores[$this->pairKey($cluster[$i], $cluster[$j])] ?? 0.0);
                $pairCount++;

                for ($k = $j + 1; $k < count($cluster); $k++) {
                    $tripletTotal += (float) ($tripletScores[$this->tripletKey($cluster[$i], $cluster[$j], $cluster[$k])] ?? 0.0);
                    $tripletCount++;
                }
            }
        }

        $pairAvg = $pairCount > 0 ? $pairTotal / $pairCount : 0.0;
        $tripletAvg = $tripletCount > 0 ? $tripletTotal / $tripletCount : 0.0;
        $profileAvg = count($cluster) > 0 ? $profileTotal / count($cluster) : 0.0;

        return round(($pairAvg * 0.42) + ($tripletAvg * 0.38) + ($profileAvg * 0.20), 8);
    }

    protected function passesStructuralTolerance(array $numbers, int $quantidadeDezenas): bool
    {
        $lines = [];
        $zones = [];

        foreach ($numbers as $number) {
            $line = $this->line((int) $number);
            $zone = $this->zone((int) $number);

            $lines[$line] = ($lines[$line] ?? 0) + 1;
            $zones[$zone] = ($zones[$zone] ?? 0) + 1;
        }

        $lineLimit = match ($quantidadeDezenas) {
            16 => 5,
            17 => 5,
            18 => 6,
            19 => 6,
            20 => 7,
            default => 6,
        };

        $zoneLimit = match ($quantidadeDezenas) {
            16 => 8,
            17 => 8,
            18 => 9,
            19 => 10,
            20 => 10,
            default => 9,
        };

        if (! empty($lines) && max($lines) > $lineLimit) {
            return false;
        }

        if (! empty($zones) && max($zones) > $zoneLimit) {
            return false;
        }

        return true;
    }

    protected function clusterTargetSize(int $quantidadeDezenas): int
    {
        return match ($quantidadeDezenas) {
            16 => 6,
            17 => 6,
            18 => 7,
            19 => 7,
            20 => 8,
            default => 7,
        };
    }

    protected function normalizeMap(array $scores): array
    {
        if (empty($scores)) {
            return [];
        }

        $min = min($scores);
        $max = max($scores);

        if ($max <= $min) {
            return array_map(fn () => 0.5, $scores);
        }

        foreach ($scores as $key => $value) {
            $scores[$key] = ($value - $min) / ($max - $min);
        }

        return $scores;
    }

    protected function uniqueClusters(array $clusters): array
    {
        $unique = [];

        foreach ($clusters as $cluster) {
            $key = $this->key($cluster['numbers'] ?? []);

            if (! isset($unique[$key]) || ($cluster['strength'] ?? 0) > ($unique[$key]['strength'] ?? 0)) {
                $unique[$key] = $cluster;
            }
        }

        return array_values($unique);
    }

    protected function uniqueBases(array $bases): array
    {
        $unique = [];

        foreach ($bases as $base) {
            $key = $this->key($base['numbers'] ?? []);

            if (! isset($unique[$key]) || ($base['cluster_strength'] ?? 0) > ($unique[$key]['cluster_strength'] ?? 0)) {
                $unique[$key] = $base;
            }
        }

        return array_values($unique);
    }

    protected function pairKey(int $a, int $b): string
    {
        $numbers = [$a, $b];
        sort($numbers);

        return implode('-', $numbers);
    }

    protected function tripletKey(int $a, int $b, int $c): string
    {
        $numbers = [$a, $b, $c];
        sort($numbers);

        return implode('-', $numbers);
    }

    protected function normalizeNumbers(array $numbers): array
    {
        $numbers = array_values(array_unique(array_map('intval', $numbers)));
        sort($numbers);

        return $numbers;
    }

    protected function key(array $numbers): string
    {
        return implode('-', $this->normalizeNumbers($numbers));
    }

    protected function line(int $number): int
    {
        return (int) floor(($number - 1) / 5) + 1;
    }

    protected function zone(int $number): int
    {
        if ($number <= 8) {
            return 1;
        }

        if ($number <= 17) {
            return 2;
        }

        return 3;
    }
}
