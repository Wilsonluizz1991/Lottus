<?php

namespace App\Services\Lottus\Fechamento;

use App\Models\LotofacilConcurso;

class FechamentoCandidateSelector
{
    protected array $lastUniverseCandidates = [];

    public function select(
        int $quantidadeDezenas,
        array $frequencyContext,
        array $delayContext,
        array $correlationContext,
        array $structureContext,
        array $cycleContext,
        LotofacilConcurso $concursoBase
    ): array {
        $bases = $this->selectMany(
            quantidadeDezenas: $quantidadeDezenas,
            frequencyContext: $frequencyContext,
            delayContext: $delayContext,
            correlationContext: $correlationContext,
            structureContext: $structureContext,
            cycleContext: $cycleContext,
            concursoBase: $concursoBase,
            limit: 1
        );

        return $bases[0] ?? [];
    }

    public function selectMany(
        int $quantidadeDezenas,
        array $frequencyContext,
        array $delayContext,
        array $correlationContext,
        array $structureContext,
        array $cycleContext,
        LotofacilConcurso $concursoBase,
        int $limit = 12
    ): array {
        $lastDraw = $this->extractNumbers($concursoBase);
        $frequencyScores = $this->normalizeScores($frequencyContext['scores'] ?? []);
        $delayScores = $this->normalizeScores($delayContext['scores'] ?? []);
        $cycleScores = $this->normalizeScores($cycleContext['scores'] ?? []);
        $pairScores = $correlationContext['pair_scores'] ?? [];
        $faltantes = array_values(array_map('intval', $cycleContext['faltantes'] ?? []));

        $numberScores = $this->buildNumberScores(
            frequencyScores: $frequencyScores,
            delayScores: $delayScores,
            cycleScores: $cycleScores,
            pairScores: $pairScores,
            lastDraw: $lastDraw,
            faltantes: $faltantes,
            concursoBase: $concursoBase,
            quantidadeDezenas: $quantidadeDezenas
        );

        $universes = $this->buildUniverseCandidates(
            quantidadeDezenas: $quantidadeDezenas,
            numberScores: $numberScores,
            lastDraw: $lastDraw,
            faltantes: $faltantes,
            concursoBase: $concursoBase
        );

        $universes = $this->uniqueUniverses($universes);

        usort($universes, function (array $a, array $b): int {
            if (($a['fitness'] ?? 0) === ($b['fitness'] ?? 0)) {
                return strcmp($a['strategy'] ?? '', $b['strategy'] ?? '');
            }

            return ($b['fitness'] ?? 0) <=> ($a['fitness'] ?? 0);
        });

        $this->lastUniverseCandidates = $universes;

        $bases = [];

        foreach (array_slice($universes, 0, max(1, $limit)) as $universe) {
            $base = $this->normalizeNumbers($universe['numbers'] ?? []);

            if (count($base) === $quantidadeDezenas) {
                $bases[] = $base;
            }
        }

        if (empty($bases)) {
            $fallback = $this->fallbackBalancedSelection($numberScores, $quantidadeDezenas);

            if (count($fallback) === $quantidadeDezenas) {
                $bases[] = $fallback;
            }
        }

        if (empty($bases)) {
            $fallback = $this->fallbackTopSelection($numberScores, $quantidadeDezenas);

            if (count($fallback) === $quantidadeDezenas) {
                $bases[] = $fallback;
            }
        }

        logger()->info('FECHAMENTO_META_SELECTOR_MANY', [
            'concurso' => $concursoBase->concurso,
            'quantidade_dezenas' => $quantidadeDezenas,
            'bases' => $bases,
            'universes' => array_map(fn (array $universe): array => [
                'strategy' => $universe['strategy'] ?? null,
                'fitness' => $universe['fitness'] ?? null,
                'numbers' => $universe['numbers'] ?? [],
                'profile' => $universe['profile'] ?? [],
            ], $universes),
        ]);

        return $bases;
    }

    public function getLastUniverseCandidates(): array
    {
        return $this->lastUniverseCandidates;
    }

    protected function buildNumberScores(
        array $frequencyScores,
        array $delayScores,
        array $cycleScores,
        array $pairScores,
        array $lastDraw,
        array $faltantes,
        LotofacilConcurso $concursoBase,
        int $quantidadeDezenas
    ): array {
        $numberScores = [];

        foreach (range(1, 25) as $number) {
            $frequency = (float) ($frequencyScores[$number] ?? 0.5);
            $delay = (float) ($delayScores[$number] ?? 0.5);
            $cycle = (float) ($cycleScores[$number] ?? 0.5);
            $correlation = $this->averageCorrelation($number, $pairScores);
            $lastDrawPresence = in_array($number, $lastDraw, true) ? 1.0 : 0.0;
            $cycleMissingPresence = in_array($number, $faltantes, true) ? 1.0 : 0.0;

            $returnPressure = max(0.0, min(1.0,
                ($delay * 0.42) +
                ($cycle * 0.32) +
                ($cycleMissingPresence * 0.18) +
                ((1.0 - $frequency) * 0.08)
            ));

            $stability = $this->stabilityScore(
                frequency: $frequency,
                delay: $delay,
                cycle: $cycle
            );

            $affinity = $this->affinityScore(
                number: $number,
                pairScores: $pairScores,
                lastDraw: $lastDraw
            );

            $antiRecency = max(0.0, min(1.0,
                ((1.0 - $lastDrawPresence) * 0.55) +
                ($returnPressure * 0.45)
            ));

            $balanced = max(0.0, min(1.0,
                ($frequency * 0.20) +
                ($delay * 0.14) +
                ($cycle * 0.16) +
                ($correlation * 0.14) +
                ($affinity * 0.12) +
                ($returnPressure * 0.14) +
                ($stability * 0.10)
            ));

            $rupture = max(0.0, min(1.0,
                ($returnPressure * 0.34) +
                ($delay * 0.22) +
                ($cycle * 0.20) +
                ($antiRecency * 0.16) +
                ((1.0 - $frequency) * 0.08)
            ));

            $conservative = max(0.0, min(1.0,
                ($frequency * 0.34) +
                ($correlation * 0.18) +
                ($affinity * 0.18) +
                ($stability * 0.18) +
                ($lastDrawPresence * 0.12)
            ));

            $explosive = max(0.0, min(1.0,
                ($correlation * 0.26) +
                ($affinity * 0.20) +
                ($returnPressure * 0.20) +
                ($antiRecency * 0.14) +
                ($cycle * 0.10) +
                ((1.0 - abs($frequency - 0.55)) * 0.10)
            ));

            $score =
                ($balanced * 0.30) +
                ($rupture * 0.24) +
                ($explosive * 0.24) +
                ($conservative * 0.10) +
                ($antiRecency * 0.08) +
                ($this->deterministicVariance($number, $concursoBase, $quantidadeDezenas) * 0.04);

            $score -= $this->extremeBiasPenalty($frequency, $delay, $cycle, $lastDrawPresence);

            $numberScores[$number] = [
                'number' => $number,
                'score' => round(max(0.0, min(1.0, $score)), 8),
                'balanced' => round($balanced, 8),
                'rupture' => round($rupture, 8),
                'conservative' => round($conservative, 8),
                'explosive' => round($explosive, 8),
                'anti_recency' => round($antiRecency, 8),
                'return_pressure' => round($returnPressure, 8),
                'stability' => round($stability, 8),
                'affinity' => round($affinity, 8),
                'frequency' => $frequency,
                'delay' => $delay,
                'cycle' => $cycle,
                'correlation' => $correlation,
                'last_draw_presence' => $lastDrawPresence,
                'cycle_missing_presence' => $cycleMissingPresence,
                'temperature' => $this->temperature($frequency, $delay, $cycle),
                'line' => $this->line($number),
                'zone' => $this->zone($number),
                'is_edge' => $this->isEdge($number),
            ];
        }

        return $numberScores;
    }

    protected function buildUniverseCandidates(
        int $quantidadeDezenas,
        array $numberScores,
        array $lastDraw,
        array $faltantes,
        LotofacilConcurso $concursoBase
    ): array {
        $strategies = [
            'balanced_primary' => [
                'balanced' => 0.34,
                'rupture' => 0.16,
                'explosive' => 0.22,
                'conservative' => 0.10,
                'anti_recency' => 0.08,
                'return_pressure' => 0.10,
            ],
            'joint_explosion' => [
                'explosive' => 0.36,
                'affinity' => 0.18,
                'correlation' => 0.18,
                'rupture' => 0.14,
                'return_pressure' => 0.10,
                'balanced' => 0.04,
            ],
            'pair_synergy' => [
                'affinity' => 0.30,
                'correlation' => 0.28,
                'explosive' => 0.18,
                'balanced' => 0.10,
                'return_pressure' => 0.08,
                'stability' => 0.06,
            ],
            'high_ceiling' => [
                'explosive' => 0.32,
                'rupture' => 0.26,
                'anti_recency' => 0.14,
                'return_pressure' => 0.14,
                'affinity' => 0.10,
                'balanced' => 0.04,
            ],
            'anti_last_draw_bias' => [
                'balanced' => 0.18,
                'rupture' => 0.26,
                'explosive' => 0.20,
                'conservative' => 0.04,
                'anti_recency' => 0.22,
                'return_pressure' => 0.10,
            ],
            'return_pressure_core' => [
                'balanced' => 0.16,
                'rupture' => 0.30,
                'explosive' => 0.20,
                'conservative' => 0.04,
                'anti_recency' => 0.12,
                'return_pressure' => 0.18,
            ],
            'correlation_stable' => [
                'balanced' => 0.18,
                'conservative' => 0.18,
                'explosive' => 0.22,
                'affinity' => 0.20,
                'correlation' => 0.16,
                'stability' => 0.06,
            ],
            'hybrid_entropy' => [
                'balanced' => 0.22,
                'rupture' => 0.20,
                'explosive' => 0.22,
                'anti_recency' => 0.14,
                'conservative' => 0.08,
                'return_pressure' => 0.14,
            ],
            'cold_rescue' => [
                'balanced' => 0.12,
                'rupture' => 0.28,
                'explosive' => 0.20,
                'anti_recency' => 0.16,
                'return_pressure' => 0.18,
                'stability' => 0.06,
            ],
            'low_overlap_recovery' => [
                'balanced' => 0.12,
                'rupture' => 0.24,
                'explosive' => 0.22,
                'anti_recency' => 0.22,
                'return_pressure' => 0.14,
                'conservative' => 0.06,
            ],
            'deep_cycle_recovery' => [
                'cycle_missing_presence' => 0.20,
                'return_pressure' => 0.24,
                'rupture' => 0.22,
                'explosive' => 0.20,
                'balanced' => 0.08,
                'anti_recency' => 0.06,
            ],
            'asymmetric_14_hunt' => [
                'explosive' => 0.34,
                'rupture' => 0.24,
                'affinity' => 0.14,
                'return_pressure' => 0.12,
                'anti_recency' => 0.10,
                'balanced' => 0.06,
            ],
        ];

        $universes = [];

        foreach ($strategies as $strategy => $weights) {
            $base = $this->buildBaseByStrategy(
                strategy: $strategy,
                weights: $weights,
                quantidadeDezenas: $quantidadeDezenas,
                numberScores: $numberScores,
                forcedNumbers: [],
                blockedNumbers: [],
                salt: crc32($strategy . '|' . $concursoBase->concurso)
            );

            if (count($base) !== $quantidadeDezenas) {
                continue;
            }

            $universes[] = $this->makeUniverse(
                strategy: $strategy,
                numbers: $base,
                numberScores: $numberScores,
                lastDraw: $lastDraw,
                faltantes: $faltantes
            );
        }

        $forcedRupture = $this->topNumbersByMetric($numberScores, 'rupture', min(6, max(3, (int) floor($quantidadeDezenas * 0.28))));
        $blockedRecent = $this->topNumbersByMetric($numberScores, 'last_draw_presence', min(4, max(2, (int) floor($quantidadeDezenas * 0.18))));

        $universes[] = $this->makeUniverse(
            strategy: 'forced_rupture',
            numbers: $this->buildBaseByStrategy(
                strategy: 'forced_rupture',
                weights: [
                    'balanced' => 0.20,
                    'rupture' => 0.36,
                    'anti_recency' => 0.24,
                    'return_pressure' => 0.20,
                ],
                quantidadeDezenas: $quantidadeDezenas,
                numberScores: $numberScores,
                forcedNumbers: $forcedRupture,
                blockedNumbers: array_slice($blockedRecent, 0, 2),
                salt: crc32('forced_rupture|' . $concursoBase->concurso)
            ),
            numberScores: $numberScores,
            lastDraw: $lastDraw,
            faltantes: $faltantes
        );

        $universes[] = $this->makeUniverse(
            strategy: 'cycle_missing_rescue',
            numbers: $this->buildBaseByStrategy(
                strategy: 'cycle_missing_rescue',
                weights: [
                    'balanced' => 0.18,
                    'rupture' => 0.24,
                    'anti_recency' => 0.14,
                    'return_pressure' => 0.24,
                    'cycle_missing_presence' => 0.20,
                ],
                quantidadeDezenas: $quantidadeDezenas,
                numberScores: $numberScores,
                forcedNumbers: array_slice($faltantes, 0, min(5, count($faltantes))),
                blockedNumbers: [],
                salt: crc32('cycle_missing_rescue|' . $concursoBase->concurso)
            ),
            numberScores: $numberScores,
            lastDraw: $lastDraw,
            faltantes: $faltantes
        );


        $forcedExplosive = $this->topNumbersByMetric($numberScores, 'explosive', min(7, max(4, (int) floor($quantidadeDezenas * 0.34))));
        $forcedAffinity = $this->topNumbersByMetric($numberScores, 'affinity', min(6, max(3, (int) floor($quantidadeDezenas * 0.28))));
        $forcedCorrelation = $this->topNumbersByMetric($numberScores, 'correlation', min(6, max(3, (int) floor($quantidadeDezenas * 0.28))));

        $universes[] = $this->makeUniverse(
            strategy: 'forced_explosive_core',
            numbers: $this->buildBaseByStrategy(
                strategy: 'forced_explosive_core',
                weights: [
                    'explosive' => 0.36,
                    'affinity' => 0.18,
                    'correlation' => 0.16,
                    'rupture' => 0.14,
                    'return_pressure' => 0.10,
                    'balanced' => 0.06,
                ],
                quantidadeDezenas: $quantidadeDezenas,
                numberScores: $numberScores,
                forcedNumbers: array_values(array_unique(array_merge($forcedExplosive, array_slice($forcedAffinity, 0, 3)))),
                blockedNumbers: [],
                salt: crc32('forced_explosive_core|' . $concursoBase->concurso)
            ),
            numberScores: $numberScores,
            lastDraw: $lastDraw,
            faltantes: $faltantes
        );

        $universes[] = $this->makeUniverse(
            strategy: 'forced_pair_synergy_core',
            numbers: $this->buildBaseByStrategy(
                strategy: 'forced_pair_synergy_core',
                weights: [
                    'affinity' => 0.30,
                    'correlation' => 0.26,
                    'explosive' => 0.20,
                    'return_pressure' => 0.10,
                    'rupture' => 0.08,
                    'balanced' => 0.06,
                ],
                quantidadeDezenas: $quantidadeDezenas,
                numberScores: $numberScores,
                forcedNumbers: array_values(array_unique(array_merge($forcedCorrelation, array_slice($forcedExplosive, 0, 3)))),
                blockedNumbers: [],
                salt: crc32('forced_pair_synergy_core|' . $concursoBase->concurso)
            ),
            numberScores: $numberScores,
            lastDraw: $lastDraw,
            faltantes: $faltantes
        );

        return array_values(array_filter(
            $universes,
            fn (array $universe) => count($universe['numbers'] ?? []) === $quantidadeDezenas
        ));
    }

    protected function buildBaseByStrategy(
        string $strategy,
        array $weights,
        int $quantidadeDezenas,
        array $numberScores,
        array $forcedNumbers,
        array $blockedNumbers,
        int $salt
    ): array {
        $weights = $this->normalizeGenericWeights($weights);
        $forcedNumbers = $this->normalizeNumbers($forcedNumbers);
        $blockedNumbers = $this->normalizeNumbers($blockedNumbers);
        $selected = [];

        foreach ($forcedNumbers as $number) {
            if (count($selected) >= $quantidadeDezenas) {
                break;
            }

            if (! isset($numberScores[$number]) || in_array($number, $blockedNumbers, true)) {
                continue;
            }

            $candidate = $selected;
            $candidate[] = $number;
            $candidate = $this->normalizeNumbers($candidate);

            if ($this->canAcceptNumber($candidate, $numberScores, $quantidadeDezenas, true)) {
                $selected = $candidate;
            }
        }

        $ranked = array_values($numberScores);

        foreach ($ranked as &$candidate) {
            $number = (int) ($candidate['number'] ?? 0);
            $score = 0.0;

            foreach ($weights as $metric => $weight) {
                $score += (float) ($candidate[$metric] ?? 0.0) * (float) $weight;
            }

            $score += $this->deterministicJitter($number, $salt);
            $candidate['_strategy_score'] = round($score, 8);
        }

        unset($candidate);

        usort($ranked, function (array $a, array $b): int {
            if (($a['_strategy_score'] ?? 0) === ($b['_strategy_score'] ?? 0)) {
                return ((int) $a['number']) <=> ((int) $b['number']);
            }

            return ($b['_strategy_score'] ?? 0) <=> ($a['_strategy_score'] ?? 0);
        });

        foreach ($this->temperatureQuotas($quantidadeDezenas, $strategy) as $temperature => $quota) {
            $bucket = array_values(array_filter(
                $ranked,
                fn (array $item) => ($item['temperature'] ?? 'neutral') === $temperature
            ));

            $this->fillFromBucket(
                selected: $selected,
                bucket: $bucket,
                needed: $quota,
                quantidadeDezenas: $quantidadeDezenas,
                numberScores: $numberScores,
                blockedNumbers: $blockedNumbers,
                relaxed: false
            );
        }

        $this->fillFromBucket(
            selected: $selected,
            bucket: $ranked,
            needed: $quantidadeDezenas - count($selected),
            quantidadeDezenas: $quantidadeDezenas,
            numberScores: $numberScores,
            blockedNumbers: $blockedNumbers,
            relaxed: true
        );

        $selected = $this->repairSelection(
            selected: $selected,
            numberScores: $numberScores,
            quantidadeDezenas: $quantidadeDezenas,
            ranked: $ranked,
            blockedNumbers: $blockedNumbers
        );

        return $this->normalizeNumbers(array_slice($selected, 0, $quantidadeDezenas));
    }

    protected function makeUniverse(
        string $strategy,
        array $numbers,
        array $numberScores,
        array $lastDraw,
        array $faltantes
    ): array {
        $numbers = $this->normalizeNumbers($numbers);
        $profile = $this->selectionProfile($numbers, $numberScores);

        $avgScore = $this->averageMetric($numbers, $numberScores, 'score');
        $avgBalanced = $this->averageMetric($numbers, $numberScores, 'balanced');
        $avgRupture = $this->averageMetric($numbers, $numberScores, 'rupture');
        $avgConservative = $this->averageMetric($numbers, $numberScores, 'conservative');
        $avgExplosive = $this->averageMetric($numbers, $numberScores, 'explosive');
        $avgAffinity = $this->averageMetric($numbers, $numberScores, 'affinity');
        $avgCorrelation = $this->averageMetric($numbers, $numberScores, 'correlation');
        $avgAntiRecency = $this->averageMetric($numbers, $numberScores, 'anti_recency');
        $avgReturnPressure = $this->averageMetric($numbers, $numberScores, 'return_pressure');
        $lineBalance = $this->balanceScore($profile['lines'] ?? [], count($numbers), 5);
        $zoneBalance = $this->balanceScore($profile['zones'] ?? [], count($numbers), 3);
        $entropy = $this->profileEntropy($profile, count($numbers));
        $lastDrawOverlap = count(array_intersect($numbers, $lastDraw));
        $faltantesOverlap = count(array_intersect($numbers, $faltantes));
        $pairSynergy = $this->basePairSynergy($numbers, $numberScores);
        $ceilingPotential = $this->ceilingPotential(
            numbers: $numbers,
            numberScores: $numberScores,
            lastDrawOverlap: $lastDrawOverlap,
            faltantesOverlap: $faltantesOverlap,
            pairSynergy: $pairSynergy
        );

        $overlapPenalty = max(0, $lastDrawOverlap - $this->maxLastDrawOverlap(count($numbers))) * 2.2;
        $lowRupturePenalty = $avgRupture < 0.34 ? 5.0 : 0.0;
        $lowEntropyPenalty = $entropy < 0.52 ? 4.0 : 0.0;

        $fitness =
            ($ceilingPotential * 42.0) +
            ($pairSynergy * 28.0) +
            ($avgExplosive * 26.0) +
            ($avgAffinity * 18.0) +
            ($avgCorrelation * 18.0) +
            ($avgRupture * 16.0) +
            ($avgReturnPressure * 12.0) +
            ($avgScore * 10.0) +
            ($avgBalanced * 7.0) +
            ($avgAntiRecency * 7.0) +
            ($lineBalance * 4.0) +
            ($zoneBalance * 4.0) +
            ($entropy * 4.0) +
            (min(6, $faltantesOverlap) * 2.8) -
            $overlapPenalty -
            $lowRupturePenalty -
            $lowEntropyPenalty;

        return [
            'strategy' => $strategy,
            'numbers' => $numbers,
            'key' => implode('-', $numbers),
            'fitness' => round($fitness, 8),
            'profile' => $profile + [
                'avg_score' => round($avgScore, 8),
                'avg_balanced' => round($avgBalanced, 8),
                'avg_rupture' => round($avgRupture, 8),
                'avg_conservative' => round($avgConservative, 8),
                'avg_explosive' => round($avgExplosive, 8),
                'avg_affinity' => round($avgAffinity, 8),
                'avg_correlation' => round($avgCorrelation, 8),
                'pair_synergy' => round($pairSynergy, 8),
                'ceiling_potential' => round($ceilingPotential, 8),
                'avg_anti_recency' => round($avgAntiRecency, 8),
                'avg_return_pressure' => round($avgReturnPressure, 8),
                'line_balance' => round($lineBalance, 8),
                'zone_balance' => round($zoneBalance, 8),
                'entropy' => round($entropy, 8),
                'last_draw_overlap' => $lastDrawOverlap,
                'faltantes_overlap' => $faltantesOverlap,
            ],
        ];
    }

    protected function fillFromBucket(
        array &$selected,
        array $bucket,
        int $needed,
        int $quantidadeDezenas,
        array $numberScores,
        array $blockedNumbers = [],
        bool $relaxed = false
    ): void {
        foreach ($bucket as $candidate) {
            if ($needed <= 0 || count($selected) >= $quantidadeDezenas) {
                break;
            }

            $number = (int) ($candidate['number'] ?? 0);

            if ($number < 1 || $number > 25 || in_array($number, $selected, true) || in_array($number, $blockedNumbers, true)) {
                continue;
            }

            $test = $selected;
            $test[] = $number;
            $test = $this->normalizeNumbers($test);

            if (! $this->canAcceptNumber($test, $numberScores, $quantidadeDezenas, $relaxed)) {
                continue;
            }

            $selected = $test;
            $needed--;
        }
    }

    protected function repairSelection(
        array $selected,
        array $numberScores,
        int $quantidadeDezenas,
        array $ranked,
        array $blockedNumbers = []
    ): array {
        $selected = $this->normalizeNumbers($selected);

        while (count($selected) > $quantidadeDezenas) {
            $remove = $this->weakestSelectedNumber($selected, $numberScores);

            if ($remove === null) {
                break;
            }

            $selected = array_values(array_diff($selected, [$remove]));
        }

        foreach ($ranked as $candidate) {
            if (count($selected) >= $quantidadeDezenas) {
                break;
            }

            $number = (int) ($candidate['number'] ?? 0);

            if (in_array($number, $selected, true) || in_array($number, $blockedNumbers, true)) {
                continue;
            }

            $test = $selected;
            $test[] = $number;
            $test = $this->normalizeNumbers($test);

            if (! $this->canAcceptNumber($test, $numberScores, $quantidadeDezenas, true)) {
                continue;
            }

            $selected = $test;
        }

        if (count($selected) < $quantidadeDezenas) {
            foreach ($ranked as $candidate) {
                if (count($selected) >= $quantidadeDezenas) {
                    break;
                }

                $number = (int) ($candidate['number'] ?? 0);

                if (! in_array($number, $selected, true) && ! in_array($number, $blockedNumbers, true)) {
                    $selected[] = $number;
                    $selected = $this->normalizeNumbers($selected);
                }
            }
        }

        return $selected;
    }

    protected function canAcceptNumber(
        array $selected,
        array $numberScores,
        int $quantidadeDezenas,
        bool $relaxed = false
    ): bool {
        $lineLimits = $this->lineLimits($quantidadeDezenas, $relaxed);
        $zoneLimits = $this->zoneLimits($quantidadeDezenas, $relaxed);
        $temperatureLimits = $this->temperatureLimits($quantidadeDezenas, $relaxed);

        $lines = [];
        $zones = [];
        $temperatures = [];

        foreach ($selected as $number) {
            $line = $this->line((int) $number);
            $zone = $this->zone((int) $number);
            $temperature = $numberScores[(int) $number]['temperature'] ?? 'neutral';

            $lines[$line] = ($lines[$line] ?? 0) + 1;
            $zones[$zone] = ($zones[$zone] ?? 0) + 1;
            $temperatures[$temperature] = ($temperatures[$temperature] ?? 0) + 1;
        }

        foreach ($lines as $line => $count) {
            if ($count > ($lineLimits['max'][$line] ?? 5)) {
                return false;
            }
        }

        foreach ($zones as $zone => $count) {
            if ($count > ($zoneLimits['max'][$zone] ?? 10)) {
                return false;
            }
        }

        foreach ($temperatures as $temperature => $count) {
            if ($count > ($temperatureLimits['max'][$temperature] ?? $quantidadeDezenas)) {
                return false;
            }
        }

        return true;
    }

    protected function fallbackBalancedSelection(array $numberScores, int $quantidadeDezenas): array
    {
        $ranked = array_values($numberScores);

        usort($ranked, function (array $a, array $b): int {
            if (($a['score'] ?? 0) === ($b['score'] ?? 0)) {
                return ((int) $a['number']) <=> ((int) $b['number']);
            }

            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });

        $selected = [];

        foreach ($ranked as $candidate) {
            if (count($selected) >= $quantidadeDezenas) {
                break;
            }

            $number = (int) ($candidate['number'] ?? 0);

            if (in_array($number, $selected, true)) {
                continue;
            }

            $test = $selected;
            $test[] = $number;
            $test = $this->normalizeNumbers($test);

            if (! $this->canAcceptNumber($test, $numberScores, $quantidadeDezenas, true)) {
                continue;
            }

            $selected = $test;
        }

        return $selected;
    }

    protected function fallbackTopSelection(array $numberScores, int $quantidadeDezenas): array
    {
        $ranked = array_values($numberScores);

        usort($ranked, fn (array $a, array $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        $selected = array_map(
            fn (array $item) => (int) $item['number'],
            array_slice($ranked, 0, $quantidadeDezenas)
        );

        return $this->normalizeNumbers($selected);
    }

    protected function topNumbersByMetric(array $numberScores, string $metric, int $limit): array
    {
        $ranked = array_values($numberScores);

        usort($ranked, function (array $a, array $b) use ($metric): int {
            if (($a[$metric] ?? 0) === ($b[$metric] ?? 0)) {
                return ((int) $a['number']) <=> ((int) $b['number']);
            }

            return ($b[$metric] ?? 0) <=> ($a[$metric] ?? 0);
        });

        return array_map(
            fn (array $item): int => (int) $item['number'],
            array_slice($ranked, 0, $limit)
        );
    }


    protected function basePairSynergy(array $numbers, array $numberScores): float
    {
        $numbers = $this->normalizeNumbers($numbers);

        if (count($numbers) < 2) {
            return 0.0;
        }

        $total = 0.0;
        $count = 0;

        for ($i = 0; $i < count($numbers); $i++) {
            for ($j = $i + 1; $j < count($numbers); $j++) {
                $a = $numbers[$i];
                $b = $numbers[$j];

                $aAffinity = (float) ($numberScores[$a]['affinity'] ?? 0.5);
                $bAffinity = (float) ($numberScores[$b]['affinity'] ?? 0.5);
                $aCorrelation = (float) ($numberScores[$a]['correlation'] ?? 0.5);
                $bCorrelation = (float) ($numberScores[$b]['correlation'] ?? 0.5);
                $aExplosive = (float) ($numberScores[$a]['explosive'] ?? 0.5);
                $bExplosive = (float) ($numberScores[$b]['explosive'] ?? 0.5);

                $total += (($aAffinity + $bAffinity) / 2 * 0.34) +
                    (($aCorrelation + $bCorrelation) / 2 * 0.34) +
                    (($aExplosive + $bExplosive) / 2 * 0.32);

                $count++;
            }
        }

        return max(0.0, min(1.0, $total / max(1, $count)));
    }

    protected function ceilingPotential(
        array $numbers,
        array $numberScores,
        int $lastDrawOverlap,
        int $faltantesOverlap,
        float $pairSynergy
    ): float {
        $numbers = $this->normalizeNumbers($numbers);

        if (empty($numbers)) {
            return 0.0;
        }

        $avgExplosive = $this->averageMetric($numbers, $numberScores, 'explosive');
        $avgRupture = $this->averageMetric($numbers, $numberScores, 'rupture');
        $avgAffinity = $this->averageMetric($numbers, $numberScores, 'affinity');
        $avgCorrelation = $this->averageMetric($numbers, $numberScores, 'correlation');
        $avgReturnPressure = $this->averageMetric($numbers, $numberScores, 'return_pressure');
        $overlapQuality = 1.0 - min(1.0, abs($lastDrawOverlap - $this->targetLastDrawOverlap(count($numbers))) / max(1, count($numbers)));
        $cycleQuality = min(1.0, $faltantesOverlap / max(1, min(6, count($numbers))));

        return max(0.0, min(1.0,
            ($avgExplosive * 0.24) +
            ($pairSynergy * 0.22) +
            ($avgCorrelation * 0.16) +
            ($avgAffinity * 0.14) +
            ($avgRupture * 0.10) +
            ($avgReturnPressure * 0.08) +
            ($overlapQuality * 0.04) +
            ($cycleQuality * 0.02)
        ));
    }

    protected function targetLastDrawOverlap(int $quantidadeDezenas): int
    {
        return match ($quantidadeDezenas) {
            16 => 9,
            17 => 9,
            18 => 10,
            19 => 10,
            20 => 11,
            default => 10,
        };
    }

    protected function averageMetric(array $numbers, array $numberScores, string $metric): float
    {
        if (empty($numbers)) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($numbers as $number) {
            $total += (float) ($numberScores[(int) $number][$metric] ?? 0.0);
        }

        return $total / count($numbers);
    }

    protected function uniqueUniverses(array $universes): array
    {
        $unique = [];

        foreach ($universes as $universe) {
            $numbers = $this->normalizeNumbers($universe['numbers'] ?? []);
            $key = implode('-', $numbers);

            if ($key === '') {
                continue;
            }

            if (! isset($unique[$key]) || (($universe['fitness'] ?? 0) > ($unique[$key]['fitness'] ?? 0))) {
                $universe['numbers'] = $numbers;
                $universe['key'] = $key;
                $unique[$key] = $universe;
            }
        }

        return array_values($unique);
    }

    protected function normalizeGenericWeights(array $weights): array
    {
        $clean = [];

        foreach ($weights as $key => $value) {
            $clean[$key] = max(0.0, (float) $value);
        }

        $sum = array_sum($clean);

        if ($sum <= 0) {
            return $clean;
        }

        foreach ($clean as $key => $value) {
            $clean[$key] = $value / $sum;
        }

        return $clean;
    }

    protected function stabilityScore(float $frequency, float $delay, float $cycle): float
    {
        $frequencyStability = 1.0 - min(1.0, abs($frequency - 0.55) / 0.55);
        $delayStability = 1.0 - min(1.0, abs($delay - 0.45) / 0.55);
        $cycleStability = 1.0 - min(1.0, abs($cycle - 0.50) / 0.50);

        return max(0.0, min(1.0,
            ($frequencyStability * 0.42) +
            ($delayStability * 0.28) +
            ($cycleStability * 0.30)
        ));
    }

    protected function affinityScore(int $number, array $pairScores, array $lastDraw): float
    {
        $total = 0.0;
        $count = 0;

        foreach ($lastDraw as $other) {
            $other = (int) $other;

            if ($other === $number) {
                continue;
            }

            $total += $this->pairScore($number, $other, $pairScores);
            $count++;
        }

        if ($count === 0) {
            return 0.5;
        }

        return $this->sigmoid($total / $count);
    }

    protected function averageCorrelation(int $number, array $pairScores): float
    {
        $total = 0.0;
        $count = 0;

        foreach (range(1, 25) as $other) {
            if ($other === $number) {
                continue;
            }

            $total += $this->pairScore($number, $other, $pairScores);
            $count++;
        }

        if ($count === 0) {
            return 0.5;
        }

        return $this->sigmoid($total / $count);
    }

    protected function pairScore(int $a, int $b, array $pairScores): float
    {
        $keyA = min($a, $b) . '-' . max($a, $b);
        $keyB = $a . '-' . $b;
        $keyC = $b . '-' . $a;

        if (isset($pairScores[$a][$b])) {
            return (float) $pairScores[$a][$b];
        }

        if (isset($pairScores[$b][$a])) {
            return (float) $pairScores[$b][$a];
        }

        if (isset($pairScores[$keyA])) {
            return (float) $pairScores[$keyA];
        }

        if (isset($pairScores[$keyB])) {
            return (float) $pairScores[$keyB];
        }

        if (isset($pairScores[$keyC])) {
            return (float) $pairScores[$keyC];
        }

        return 0.0;
    }

    protected function sigmoid(float $value): float
    {
        return 1.0 / (1.0 + exp(-$value));
    }

    protected function normalizeScores(array $scores): array
    {
        $normalized = [];
        $values = [];

        foreach (range(1, 25) as $number) {
            $values[$number] = (float) ($scores[$number] ?? 0.0);
        }

        $min = min($values);
        $max = max($values);

        foreach ($values as $number => $value) {
            if ($max <= $min) {
                $normalized[$number] = 0.5;
                continue;
            }

            $normalized[$number] = ($value - $min) / ($max - $min);
        }

        return $normalized;
    }

    protected function deterministicVariance(
        int $number,
        LotofacilConcurso $concursoBase,
        int $quantidadeDezenas
    ): float {
        $seed = (((int) $concursoBase->concurso * 17) + ($number * 31) + ($quantidadeDezenas * 13)) % 100;

        return match (true) {
            $seed <= 8 => 0.10,
            $seed <= 22 => 0.18,
            $seed <= 68 => 0.50,
            $seed <= 86 => 0.82,
            default => 0.90,
        };
    }

    protected function deterministicJitter(int $number, int $salt): float
    {
        return ((crc32($number . '|' . $salt) % 1000) / 1000) * 0.008;
    }

    protected function extremeBiasPenalty(float $frequency, float $delay, float $cycle, float $lastDrawPresence): float
    {
        $penalty = 0.0;

        if ($frequency >= 0.90 && $delay <= 0.10) {
            $penalty += 0.055;
        }

        if ($frequency <= 0.08 && $delay >= 0.92) {
            $penalty += 0.040;
        }

        if ($cycle <= 0.08 || $cycle >= 0.92) {
            $penalty += 0.025;
        }

        if ($lastDrawPresence >= 1.0 && $frequency >= 0.72) {
            $penalty += 0.030;
        }

        return $penalty;
    }

    protected function temperature(float $frequency, float $delay, float $cycle): string
    {
        $temperatureScore = ($frequency * 0.48) + ((1.0 - $delay) * 0.22) + ($cycle * 0.30);

        if ($temperatureScore >= 0.66) {
            return 'hot';
        }

        if ($temperatureScore <= 0.38) {
            return 'cold';
        }

        return 'neutral';
    }

    protected function temperatureQuotas(int $quantidadeDezenas, string $strategy): array
    {
        $base = match ($quantidadeDezenas) {
            16 => ['hot' => 5, 'neutral' => 7, 'cold' => 4],
            17 => ['hot' => 5, 'neutral' => 8, 'cold' => 4],
            18 => ['hot' => 6, 'neutral' => 8, 'cold' => 4],
            19 => ['hot' => 6, 'neutral' => 8, 'cold' => 5],
            20 => ['hot' => 7, 'neutral' => 8, 'cold' => 5],
            default => ['hot' => 6, 'neutral' => 8, 'cold' => 4],
        };

        if (str_contains($strategy, 'explosive') || str_contains($strategy, 'ceiling') || str_contains($strategy, 'hunt')) {
            $base['hot'] = max(4, $base['hot'] - 1);
            $base['neutral'] = $base['neutral'] + 1;
        }

        if (str_contains($strategy, 'rupture') || str_contains($strategy, 'cold') || str_contains($strategy, 'cycle')) {
            $base['hot'] = max(4, $base['hot'] - 1);
            $base['cold'] = $base['cold'] + 1;
        }

        if (str_contains($strategy, 'correlation') || str_contains($strategy, 'pair')) {
            $base['neutral'] = $base['neutral'] + 1;
            $base['cold'] = max(3, $base['cold'] - 1);
        }

        return $base;
    }

    protected function lineLimits(int $quantidadeDezenas, bool $relaxed = false): array
    {
        $max = match ($quantidadeDezenas) {
            16 => 5,
            17 => 5,
            18 => 6,
            19 => 6,
            20 => 6,
            default => 6,
        };

        if ($relaxed) {
            $max++;
        }

        return [
            'max' => [1 => $max, 2 => $max, 3 => $max, 4 => $max, 5 => $max],
        ];
    }

    protected function zoneLimits(int $quantidadeDezenas, bool $relaxed = false): array
    {
        $extra = $relaxed ? 1 : 0;

        return match ($quantidadeDezenas) {
            16 => ['max' => [1 => 7 + $extra, 2 => 8 + $extra, 3 => 7 + $extra]],
            17 => ['max' => [1 => 7 + $extra, 2 => 8 + $extra, 3 => 7 + $extra]],
            18 => ['max' => [1 => 8 + $extra, 2 => 9 + $extra, 3 => 8 + $extra]],
            19 => ['max' => [1 => 8 + $extra, 2 => 9 + $extra, 3 => 8 + $extra]],
            20 => ['max' => [1 => 9 + $extra, 2 => 10 + $extra, 3 => 9 + $extra]],
            default => ['max' => [1 => 8 + $extra, 2 => 9 + $extra, 3 => 8 + $extra]],
        };
    }

    protected function temperatureLimits(int $quantidadeDezenas, bool $relaxed = false): array
    {
        $extra = $relaxed ? 1 : 0;

        return match ($quantidadeDezenas) {
            16 => ['max' => ['hot' => 8 + $extra, 'neutral' => 10 + $extra, 'cold' => 6 + $extra]],
            17 => ['max' => ['hot' => 8 + $extra, 'neutral' => 10 + $extra, 'cold' => 6 + $extra]],
            18 => ['max' => ['hot' => 9 + $extra, 'neutral' => 11 + $extra, 'cold' => 7 + $extra]],
            19 => ['max' => ['hot' => 9 + $extra, 'neutral' => 11 + $extra, 'cold' => 7 + $extra]],
            20 => ['max' => ['hot' => 10 + $extra, 'neutral' => 12 + $extra, 'cold' => 7 + $extra]],
            default => ['max' => ['hot' => 9 + $extra, 'neutral' => 11 + $extra, 'cold' => 7 + $extra]],
        };
    }

    protected function weakestSelectedNumber(array $selected, array $numberScores): ?int
    {
        if (empty($selected)) {
            return null;
        }

        usort($selected, function (int $a, int $b) use ($numberScores): int {
            return ($numberScores[$a]['score'] ?? 0.0) <=> ($numberScores[$b]['score'] ?? 0.0);
        });

        return (int) $selected[0];
    }

    protected function selectionProfile(array $selected, array $numberScores): array
    {
        $profile = [
            'hot' => 0,
            'neutral' => 0,
            'cold' => 0,
            'lines' => [],
            'zones' => [],
        ];

        foreach ($selected as $number) {
            $temperature = $numberScores[(int) $number]['temperature'] ?? 'neutral';
            $line = $this->line((int) $number);
            $zone = $this->zone((int) $number);

            $profile[$temperature] = ($profile[$temperature] ?? 0) + 1;
            $profile['lines'][$line] = ($profile['lines'][$line] ?? 0) + 1;
            $profile['zones'][$zone] = ($profile['zones'][$zone] ?? 0) + 1;
        }

        return $profile;
    }

    protected function balanceScore(array $distribution, int $total, int $groups): float
    {
        if ($total <= 0 || $groups <= 0) {
            return 0.0;
        }

        $expected = $total / $groups;
        $distance = 0.0;

        for ($i = 1; $i <= $groups; $i++) {
            $distance += abs(($distribution[$i] ?? 0) - $expected);
        }

        return max(0.0, min(1.0, 1.0 - ($distance / max(1.0, $total))));
    }

    protected function profileEntropy(array $profile, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        $temperatureEntropy = $this->entropyScore([
            $profile['hot'] ?? 0,
            $profile['neutral'] ?? 0,
            $profile['cold'] ?? 0,
        ], $total);

        $lineEntropy = $this->entropyScore($profile['lines'] ?? [], $total);
        $zoneEntropy = $this->entropyScore($profile['zones'] ?? [], $total);

        return max(0.0, min(1.0,
            ($temperatureEntropy * 0.34) +
            ($lineEntropy * 0.33) +
            ($zoneEntropy * 0.33)
        ));
    }

    protected function entropyScore(array $distribution, int $total): float
    {
        if ($total <= 0 || empty($distribution)) {
            return 0.0;
        }

        $entropy = 0.0;
        $groups = count($distribution);

        foreach ($distribution as $count) {
            $p = $count / $total;

            if ($p > 0) {
                $entropy -= $p * log($p);
            }
        }

        $maxEntropy = log(max(2, $groups));

        return max(0.0, min(1.0, $entropy / $maxEntropy));
    }

    protected function maxLastDrawOverlap(int $quantidadeDezenas): int
    {
        return match ($quantidadeDezenas) {
            16 => 10,
            17 => 10,
            18 => 11,
            19 => 11,
            20 => 12,
            default => 11,
        };
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

    protected function isEdge(int $number): bool
    {
        return in_array($number, [
            1, 2, 3, 4, 5,
            6, 10,
            11, 15,
            16, 20,
            21, 22, 23, 24, 25,
        ], true);
    }

    protected function normalizeNumbers(array $numbers): array
    {
        $numbers = array_values(array_unique(array_map('intval', $numbers)));
        $numbers = array_values(array_filter($numbers, fn (int $number) => $number >= 1 && $number <= 25));
        sort($numbers);

        return $numbers;
    }

    protected function extractNumbers(LotofacilConcurso $concurso): array
    {
        $numbers = [];

        for ($i = 1; $i <= 15; $i++) {
            $field = 'bola' . $i;

            if (isset($concurso->{$field})) {
                $numbers[] = (int) $concurso->{$field};
            }
        }

        return $this->normalizeNumbers($numbers);
    }
}