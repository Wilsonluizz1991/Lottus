<?php

namespace App\Services\Lottus\Fechamento;

use App\Models\LotofacilConcurso;
use Illuminate\Support\Collection;

class FechamentoBaseCompetitionService
{
    protected array $lastNumberScores = [];
    protected array $lastCompetitionReport = [];

    public function __construct(
        protected FechamentoAffinityClusterService $affinityClusterService
    ) {
    }

    public function selectWinningBase(
        array $primaryBase,
        int $quantidadeDezenas,
        Collection $historico,
        array $frequencyContext,
        array $delayContext,
        array $correlationContext,
        array $structureContext,
        array $cycleContext,
        LotofacilConcurso $concursoBase,
        array $patternContext = []
    ): array {
        $primaryBase = $this->normalizeNumbers($primaryBase);

        if ($quantidadeDezenas < 16 || $quantidadeDezenas > 20) {
            return $primaryBase;
        }

        if (count($primaryBase) !== $quantidadeDezenas) {
            return $primaryBase;
        }

        $profiles = $this->buildNumberProfiles(
            frequencyContext: $frequencyContext,
            delayContext: $delayContext,
            correlationContext: $correlationContext,
            cycleContext: $cycleContext,
            concursoBase: $concursoBase
        );

        $this->lastNumberScores = $profiles;

        $historicalDraws = $this->extractHistoricalDraws($historico, $concursoBase);

        $candidates = [];

        $candidates[] = $this->makeCandidate(
            strategy: 'selector_primary',
            numbers: $primaryBase,
            profiles: $profiles,
            quantidadeDezenas: $quantidadeDezenas,
            historicalDraws: $historicalDraws,
            patternContext: $patternContext
        );

        foreach ($this->strategies() as $strategyName => $weights) {
            $base = $this->buildBaseFromStrategy(
                profiles: $profiles,
                quantidadeDezenas: $quantidadeDezenas,
                weights: $this->applyPatternBias($weights, $strategyName, $patternContext),
                concursoBase: $concursoBase,
                patternContext: $patternContext
            );

            if (count($base) !== $quantidadeDezenas) {
                continue;
            }

            $candidates[] = $this->makeCandidate(
                strategy: $strategyName,
                numbers: $base,
                profiles: $profiles,
                quantidadeDezenas: $quantidadeDezenas,
                historicalDraws: $historicalDraws,
                patternContext: $patternContext
            );
        }

        $candidates = $this->addAffinityClusterCandidates(
            candidates: $candidates,
            profiles: $profiles,
            quantidadeDezenas: $quantidadeDezenas,
            historicalDraws: $historicalDraws,
            patternContext: $patternContext
        );

        $candidates = $this->addBlockCandidates(
            candidates: $candidates,
            profiles: $profiles,
            quantidadeDezenas: $quantidadeDezenas,
            historicalDraws: $historicalDraws,
            concursoBase: $concursoBase,
            patternContext: $patternContext
        );

        $candidates = $this->uniqueCandidates($candidates);

        usort($candidates, function (array $a, array $b): int {
            if (($a['fitness'] ?? 0) === ($b['fitness'] ?? 0)) {
                return strcmp($a['key'] ?? '', $b['key'] ?? '');
            }

            return ($b['fitness'] ?? 0) <=> ($a['fitness'] ?? 0);
        });

        $winner = $candidates[0] ?? [
            'strategy' => 'fallback_primary',
            'numbers' => $primaryBase,
            'fitness' => 0,
            'profile' => [],
            'containment' => [],
        ];

        $this->lastCompetitionReport = [
            'concurso' => $concursoBase->concurso,
            'quantidade_dezenas' => $quantidadeDezenas,
            'pattern_regime' => $patternContext['regime'] ?? null,
            'pattern_confidence' => $patternContext['confidence'] ?? null,
            'winner' => $winner,
            'candidates' => array_slice($candidates, 0, 10),
        ];

        logger()->info('FECHAMENTO_BASE_COMPETITION_V3', $this->lastCompetitionReport);

        return $winner['numbers'];
    }

    public function selectTopBases(
        array $primaryBase,
        int $quantidadeDezenas,
        Collection $historico,
        array $frequencyContext,
        array $delayContext,
        array $correlationContext,
        array $structureContext,
        array $cycleContext,
        LotofacilConcurso $concursoBase,
        array $patternContext = [],
        int $limit = 6
    ): array {
        $primaryBase = $this->normalizeNumbers($primaryBase);
        $limit = max(1, $limit);

        if ($quantidadeDezenas < 16 || $quantidadeDezenas > 20) {
            return [$primaryBase];
        }

        if (count($primaryBase) !== $quantidadeDezenas) {
            return [$primaryBase];
        }

        $profiles = $this->buildNumberProfiles(
            frequencyContext: $frequencyContext,
            delayContext: $delayContext,
            correlationContext: $correlationContext,
            cycleContext: $cycleContext,
            concursoBase: $concursoBase
        );

        $this->lastNumberScores = $profiles;

        $historicalDraws = $this->extractHistoricalDraws($historico, $concursoBase);

        $candidates = [];

        $candidates[] = $this->makeCandidate(
            strategy: 'selector_primary',
            numbers: $primaryBase,
            profiles: $profiles,
            quantidadeDezenas: $quantidadeDezenas,
            historicalDraws: $historicalDraws,
            patternContext: $patternContext
        );

        foreach ($this->strategies() as $strategyName => $weights) {
            $base = $this->buildBaseFromStrategy(
                profiles: $profiles,
                quantidadeDezenas: $quantidadeDezenas,
                weights: $this->applyPatternBias($weights, $strategyName, $patternContext),
                concursoBase: $concursoBase,
                patternContext: $patternContext
            );

            if (count($base) !== $quantidadeDezenas) {
                continue;
            }

            $candidates[] = $this->makeCandidate(
                strategy: $strategyName,
                numbers: $base,
                profiles: $profiles,
                quantidadeDezenas: $quantidadeDezenas,
                historicalDraws: $historicalDraws,
                patternContext: $patternContext
            );
        }

        $candidates = $this->addAffinityClusterCandidates(
            candidates: $candidates,
            profiles: $profiles,
            quantidadeDezenas: $quantidadeDezenas,
            historicalDraws: $historicalDraws,
            patternContext: $patternContext
        );

        $candidates = $this->addBlockCandidates(
            candidates: $candidates,
            profiles: $profiles,
            quantidadeDezenas: $quantidadeDezenas,
            historicalDraws: $historicalDraws,
            concursoBase: $concursoBase,
            patternContext: $patternContext
        );

        $candidates = $this->uniqueCandidates($candidates);

        usort($candidates, function (array $a, array $b): int {
            if (($a['fitness'] ?? 0) === ($b['fitness'] ?? 0)) {
                return strcmp($a['key'] ?? '', $b['key'] ?? '');
            }

            return ($b['fitness'] ?? 0) <=> ($a['fitness'] ?? 0);
        });

        $candidates = array_slice($candidates, 0, $limit);

        if (empty($candidates)) {
            $candidates = [[
                'strategy' => 'fallback_primary',
                'numbers' => $primaryBase,
                'fitness' => 0,
                'profile' => [],
                'containment' => [],
            ]];
        }

        $this->lastCompetitionReport = [
            'concurso' => $concursoBase->concurso,
            'quantidade_dezenas' => $quantidadeDezenas,
            'pattern_regime' => $patternContext['regime'] ?? null,
            'pattern_confidence' => $patternContext['confidence'] ?? null,
            'winner' => $candidates[0],
            'candidates' => $candidates,
        ];

        logger()->info('FECHAMENTO_BASE_COMPETITION_TOP_BASES', $this->lastCompetitionReport);

        return array_map(
            fn (array $candidate): array => $candidate['numbers'],
            $candidates
        );
    }

    public function getLastNumberScores(): array
    {
        return $this->lastNumberScores;
    }

    public function getLastCompetitionReport(): array
    {
        return $this->lastCompetitionReport;
    }

    protected function buildNumberProfiles(
        array $frequencyContext,
        array $delayContext,
        array $correlationContext,
        array $cycleContext,
        LotofacilConcurso $concursoBase
    ): array {
        $frequencyScores = $this->normalizeScores($frequencyContext['scores'] ?? []);
        $delayScores = $this->normalizeScores($delayContext['scores'] ?? []);
        $cycleScores = $this->normalizeScores($cycleContext['scores'] ?? []);
        $pairScores = $correlationContext['pair_scores'] ?? [];
        $faltantes = array_values(array_map('intval', $cycleContext['faltantes'] ?? []));
        $lastDraw = $this->extractNumbers($concursoBase);

        $profiles = [];

        foreach (range(1, 25) as $number) {
            $frequency = (float) ($frequencyScores[$number] ?? 0.0);
            $delay = (float) ($delayScores[$number] ?? 0.0);
            $cycle = (float) ($cycleScores[$number] ?? 0.0);
            $correlation = $this->averageCorrelation($number, $pairScores);
            $lastDrawPresence = in_array($number, $lastDraw, true) ? 1.0 : 0.0;
            $cycleMissingPresence = in_array($number, $faltantes, true) ? 1.0 : 0.0;
            $affinity = $this->affinityScore($number, $pairScores, $lastDraw);

            $maturity = max(0.0, min(1.0,
                ($frequency * 0.30) +
                ($delay * 0.18) +
                ($cycle * 0.22) +
                ($correlation * 0.14) +
                ($lastDrawPresence * 0.06) +
                ($cycleMissingPresence * 0.10)
            ));

            $stability = $this->stabilityScore($frequency, $delay, $cycle);

            $returnPressure = max(0.0, min(1.0,
                ($delay * 0.34) +
                ($cycle * 0.34) +
                ($cycleMissingPresence * 0.20) +
                ($affinity * 0.12)
            ));

            $persistence = max(0.0, min(1.0,
                ($frequency * 0.34) +
                ($lastDrawPresence * 0.26) +
                ($affinity * 0.22) +
                ($correlation * 0.18)
            ));

            $balanced = max(0.0, min(1.0,
                ($maturity * 0.34) +
                ($stability * 0.20) +
                ($affinity * 0.28) +
                ($returnPressure * 0.18)
            ));

            $antiBias = max(0.0, min(1.0,
                ($balanced * 0.70) +
                ((1.0 - abs($frequency - 0.55)) * 0.10) +
                ((1.0 - abs($delay - 0.45)) * 0.08) +
                ((1.0 - abs($cycle - 0.50)) * 0.12) -
                $this->extremeBiasPenalty($frequency, $delay, $cycle)
            ));

            $profiles[$number] = [
                'number' => $number,
                'score' => round($balanced, 8),
                'maturity' => round($maturity, 8),
                'stability' => round($stability, 8),
                'affinity' => round($affinity, 8),
                'return_pressure' => round($returnPressure, 8),
                'persistence' => round($persistence, 8),
                'balanced' => round($balanced, 8),
                'anti_bias' => round($antiBias, 8),
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

        return $profiles;
    }

    protected function strategies(): array
    {
        return [
            'maturity_core' => [
                'maturity' => 0.42,
                'stability' => 0.12,
                'affinity' => 0.22,
                'return_pressure' => 0.12,
                'persistence' => 0.08,
                'anti_bias' => 0.04,
            ],
            'cycle_return' => [
                'maturity' => 0.16,
                'stability' => 0.08,
                'affinity' => 0.18,
                'return_pressure' => 0.42,
                'persistence' => 0.08,
                'anti_bias' => 0.08,
            ],
            'affinity_blocks' => [
                'maturity' => 0.18,
                'stability' => 0.08,
                'affinity' => 0.46,
                'return_pressure' => 0.12,
                'persistence' => 0.12,
                'anti_bias' => 0.04,
            ],
            'persistence_recent' => [
                'maturity' => 0.16,
                'stability' => 0.08,
                'affinity' => 0.20,
                'return_pressure' => 0.10,
                'persistence' => 0.40,
                'anti_bias' => 0.06,
            ],
            'elite_convergence' => [
                'maturity' => 0.28,
                'stability' => 0.10,
                'affinity' => 0.34,
                'return_pressure' => 0.18,
                'persistence' => 0.08,
                'anti_bias' => 0.02,
            ],
            'rupture_hunter' => [
                'maturity' => 0.20,
                'stability' => 0.04,
                'affinity' => 0.26,
                'return_pressure' => 0.28,
                'persistence' => 0.04,
                'anti_bias' => 0.18,
            ],
        ];
    }

    protected function buildBaseFromStrategy(
        array $profiles,
        int $quantidadeDezenas,
        array $weights,
        LotofacilConcurso $concursoBase,
        array $patternContext = []
    ): array {
        $ranked = array_values($profiles);

        foreach ($ranked as &$item) {
            $number = (int) $item['number'];
            $score = 0.0;

            foreach ($weights as $metric => $weight) {
                $score += (float) ($item[$metric] ?? 0.0) * (float) $weight;
            }

            $score += $this->controlledRotationBonus($number, $concursoBase, $quantidadeDezenas);
            $item['_strategy_score'] = round($score, 8);
        }

        unset($item);

        usort($ranked, function (array $a, array $b): int {
            if (($a['_strategy_score'] ?? 0) === ($b['_strategy_score'] ?? 0)) {
                return ((int) $a['number']) <=> ((int) $b['number']);
            }

            return ($b['_strategy_score'] ?? 0) <=> ($a['_strategy_score'] ?? 0);
        });

        $selected = [];

        foreach ($this->temperatureQuotas($quantidadeDezenas, $patternContext) as $temperature => $quota) {
            $bucket = array_values(array_filter(
                $ranked,
                fn (array $item) => ($item['temperature'] ?? 'neutral') === $temperature
            ));

            $this->fillBase($selected, $bucket, $profiles, $quantidadeDezenas, $quota);
        }

        $this->fillBase(
            selected: $selected,
            ranked: $ranked,
            profiles: $profiles,
            quantidadeDezenas: $quantidadeDezenas,
            needed: $quantidadeDezenas - count($selected),
            relaxed: true
        );

        $selected = $this->repairBase($selected, $ranked, $profiles, $quantidadeDezenas);

        sort($selected);

        return $selected;
    }

    protected function addAffinityClusterCandidates(
        array $candidates,
        array $profiles,
        int $quantidadeDezenas,
        array $historicalDraws,
        array $patternContext
    ): array {
        $clusterBases = $this->affinityClusterService->buildClusterBases(
            historicalDraws: $historicalDraws,
            profiles: $profiles,
            quantidadeDezenas: $quantidadeDezenas,
            patternContext: $patternContext,
            maxBases: 10
        );

        foreach ($clusterBases as $clusterBase) {
            $base = $this->normalizeNumbers($clusterBase['numbers'] ?? []);

            if (count($base) !== $quantidadeDezenas) {
                continue;
            }

            $candidate = $this->makeCandidate(
                strategy: (string) ($clusterBase['strategy'] ?? 'affinity_cluster'),
                numbers: $base,
                profiles: $profiles,
                quantidadeDezenas: $quantidadeDezenas,
                historicalDraws: $historicalDraws,
                patternContext: $patternContext
            );

            $clusterStrength = (float) ($clusterBase['cluster_strength'] ?? 0.0);

            $candidate['cluster'] = $clusterBase['cluster'] ?? [];
            $candidate['cluster_strength'] = $clusterStrength;
            $candidate['fitness'] += $clusterStrength * 28.0;

            $candidates[] = $candidate;
        }

        return $candidates;
    }

    protected function addBlockCandidates(
        array $candidates,
        array $profiles,
        int $quantidadeDezenas,
        array $historicalDraws,
        LotofacilConcurso $concursoBase,
        array $patternContext
    ): array {
        $recentDraws = array_slice($historicalDraws, -40);
        $blocks = $this->eliteBlocks($recentDraws);

        foreach (array_slice($blocks, 0, 6) as $index => $block) {
            $base = $this->buildBaseAroundBlock(
                block: $block,
                profiles: $profiles,
                quantidadeDezenas: $quantidadeDezenas,
                concursoBase: $concursoBase,
                patternContext: $patternContext
            );

            if (count($base) !== $quantidadeDezenas) {
                continue;
            }

            $candidates[] = $this->makeCandidate(
                strategy: 'dynamic_block_' . ($index + 1),
                numbers: $base,
                profiles: $profiles,
                quantidadeDezenas: $quantidadeDezenas,
                historicalDraws: $historicalDraws,
                patternContext: $patternContext
            );
        }

        return $candidates;
    }

    protected function eliteBlocks(array $draws): array
    {
        $pairs = [];

        foreach ($draws as $draw) {
            $draw = $this->normalizeNumbers($draw);

            for ($i = 0; $i < count($draw); $i++) {
                for ($j = $i + 1; $j < count($draw); $j++) {
                    $key = $draw[$i] . '-' . $draw[$j];
                    $pairs[$key] = ($pairs[$key] ?? 0) + 1;
                }
            }
        }

        arsort($pairs);

        $blocks = [];

        foreach (array_slice($pairs, 0, 25, true) as $key => $count) {
            $numbers = array_map('intval', explode('-', $key));

            foreach ($pairs as $otherKey => $otherCount) {
                if ($otherKey === $key) {
                    continue;
                }

                $other = array_map('intval', explode('-', $otherKey));
                $merged = $this->normalizeNumbers(array_merge($numbers, $other));

                if (count($merged) <= 5) {
                    $numbers = $merged;
                }

                if (count($numbers) >= 5) {
                    break;
                }
            }

            $blockKey = $this->key($numbers);

            if (! isset($blocks[$blockKey])) {
                $blocks[$blockKey] = [
                    'numbers' => $numbers,
                    'strength' => $count,
                ];
            }
        }

        usort($blocks, fn (array $a, array $b) => ($b['strength'] ?? 0) <=> ($a['strength'] ?? 0));

        return array_values($blocks);
    }

    protected function buildBaseAroundBlock(
        array $block,
        array $profiles,
        int $quantidadeDezenas,
        LotofacilConcurso $concursoBase,
        array $patternContext
    ): array {
        $selected = $this->normalizeNumbers($block['numbers'] ?? []);
        $ranked = array_values($profiles);

        foreach ($ranked as &$item) {
            $number = (int) $item['number'];

            $item['_block_score'] =
                ((float) ($item['maturity'] ?? 0.0) * 0.26) +
                ((float) ($item['affinity'] ?? 0.0) * 0.34) +
                ((float) ($item['return_pressure'] ?? 0.0) * 0.22) +
                ((float) ($item['persistence'] ?? 0.0) * 0.10) +
                ((float) ($item['anti_bias'] ?? 0.0) * 0.08) +
                $this->controlledRotationBonus($number, $concursoBase, $quantidadeDezenas);
        }

        unset($item);

        usort($ranked, function (array $a, array $b): int {
            if (($a['_block_score'] ?? 0) === ($b['_block_score'] ?? 0)) {
                return ((int) $a['number']) <=> ((int) $b['number']);
            }

            return ($b['_block_score'] ?? 0) <=> ($a['_block_score'] ?? 0);
        });

        $this->fillBase(
            selected: $selected,
            ranked: $ranked,
            profiles: $profiles,
            quantidadeDezenas: $quantidadeDezenas,
            needed: $quantidadeDezenas - count($selected),
            relaxed: true
        );

        return $this->repairBase($selected, $ranked, $profiles, $quantidadeDezenas);
    }

    protected function fillBase(
        array &$selected,
        array $ranked,
        array $profiles,
        int $quantidadeDezenas,
        int $needed,
        bool $relaxed = false
    ): void {
        foreach ($ranked as $candidate) {
            if ($needed <= 0 || count($selected) >= $quantidadeDezenas) {
                break;
            }

            $number = (int) ($candidate['number'] ?? 0);

            if ($number < 1 || $number > 25 || in_array($number, $selected, true)) {
                continue;
            }

            $test = $this->normalizeNumbers(array_merge($selected, [$number]));

            if (! $this->canAcceptBase($test, $profiles, $quantidadeDezenas, $relaxed)) {
                continue;
            }

            $selected = $test;
            $needed--;
        }
    }

    protected function repairBase(array $selected, array $ranked, array $profiles, int $quantidadeDezenas): array
    {
        $selected = $this->normalizeNumbers($selected);

        while (count($selected) > $quantidadeDezenas) {
            $remove = $this->weakestNumber($selected, $profiles);

            if ($remove === null) {
                break;
            }

            $selected = array_values(array_diff($selected, [$remove]));
        }

        foreach ([false, true] as $relaxed) {
            while (count($selected) < $quantidadeDezenas) {
                $added = false;

                foreach ($ranked as $candidate) {
                    $number = (int) ($candidate['number'] ?? 0);

                    if (in_array($number, $selected, true)) {
                        continue;
                    }

                    $test = $this->normalizeNumbers(array_merge($selected, [$number]));

                    if (! $this->canAcceptBase($test, $profiles, $quantidadeDezenas, $relaxed)) {
                        continue;
                    }

                    $selected = $test;
                    $added = true;
                    break;
                }

                if (! $added) {
                    break;
                }
            }
        }

        return $selected;
    }

    protected function makeCandidate(
        string $strategy,
        array $numbers,
        array $profiles,
        int $quantidadeDezenas,
        array $historicalDraws,
        array $patternContext
    ): array {
        $numbers = $this->normalizeNumbers($numbers);
        $profile = $this->baseProfile($numbers, $profiles);
        $containment = $this->containmentScore($numbers, $historicalDraws, $patternContext);

        return [
            'strategy' => $strategy,
            'numbers' => $numbers,
            'key' => $this->key($numbers),
            'fitness' => $this->baseFitness($numbers, $profiles, $quantidadeDezenas, $profile, $containment),
            'profile' => $profile,
            'containment' => $containment,
        ];
    }

    protected function baseFitness(
        array $base,
        array $profiles,
        int $quantidadeDezenas,
        array $profile,
        array $containment
    ): float {
        if (count($base) !== $quantidadeDezenas) {
            return -9999.0;
        }

        $fitness = 0.0;

        $fitness += $containment['weighted_score'] * 46.0;
        $fitness += $containment['rate_13_plus'] * 22.0;
        $fitness += $containment['rate_14_plus'] * 45.0;
        $fitness += $profile['avg_maturity'] * 10.0;
        $fitness += $profile['avg_affinity'] * 16.0;
        $fitness += $profile['avg_return_pressure'] * 12.0;
        $fitness += $profile['avg_anti_bias'] * 5.0;
        $fitness += $profile['temperature_balance'] * 4.0;
        $fitness += $profile['line_balance'] * 4.0;
        $fitness += $profile['zone_balance'] * 3.0;
        $fitness += $profile['entropy'] * 3.0;

        if ($profile['max_line_count'] > $this->lineLimit($quantidadeDezenas) + 1) {
            $fitness -= 8.0;
        }

        if ($profile['max_zone_count'] > $this->zoneLimit($quantidadeDezenas) + 1) {
            $fitness -= 6.0;
        }

        return round($fitness, 8);
    }

    protected function containmentScore(array $base, array $historicalDraws, array $patternContext): array
    {
        $draws = array_slice($historicalDraws, -80);

        if (empty($draws)) {
            return [
                'avg_hits' => 0.0,
                'max_hits' => 0,
                'rate_12_plus' => 0.0,
                'rate_13_plus' => 0.0,
                'rate_14_plus' => 0.0,
                'weighted_score' => 0.0,
            ];
        }

        $totalHits = 0;
        $maxHits = 0;
        $count12 = 0;
        $count13 = 0;
        $count14 = 0;
        $weighted = 0.0;
        $weightTotal = 0.0;

        foreach ($draws as $index => $draw) {
            $hits = count(array_intersect($base, $draw));
            $ageWeight = ($index + 1) / count($draws);

            $totalHits += $hits;
            $maxHits = max($maxHits, $hits);

            if ($hits >= 12) {
                $count12++;
            }

            if ($hits >= 13) {
                $count13++;
            }

            if ($hits >= 14) {
                $count14++;
            }

            $hitScore = match (true) {
                $hits >= 15 => 1.00,
                $hits === 14 => 0.88,
                $hits === 13 => 0.58,
                $hits === 12 => 0.30,
                $hits === 11 => 0.12,
                default => 0.0,
            };

            $weighted += $hitScore * $ageWeight;
            $weightTotal += $ageWeight;
        }

        $total = count($draws);

        return [
            'avg_hits' => round($totalHits / $total, 8),
            'max_hits' => $maxHits,
            'rate_12_plus' => round($count12 / $total, 8),
            'rate_13_plus' => round($count13 / $total, 8),
            'rate_14_plus' => round($count14 / $total, 8),
            'weighted_score' => round($weighted / max(0.0001, $weightTotal), 8),
        ];
    }

    protected function baseProfile(array $base, array $profiles): array
    {
        $base = $this->normalizeNumbers($base);

        $maturity = [];
        $stability = [];
        $affinity = [];
        $returnPressure = [];
        $antiBias = [];
        $temperatures = ['hot' => 0, 'neutral' => 0, 'cold' => 0];
        $lines = [];
        $zones = [];

        foreach ($base as $number) {
            $profile = $profiles[$number] ?? [];

            $maturity[] = (float) ($profile['maturity'] ?? 0.0);
            $stability[] = (float) ($profile['stability'] ?? 0.0);
            $affinity[] = (float) ($profile['affinity'] ?? 0.0);
            $returnPressure[] = (float) ($profile['return_pressure'] ?? 0.0);
            $antiBias[] = (float) ($profile['anti_bias'] ?? 0.0);

            $temperature = $profile['temperature'] ?? 'neutral';
            $temperatures[$temperature] = ($temperatures[$temperature] ?? 0) + 1;

            $line = $this->line($number);
            $zone = $this->zone($number);

            $lines[$line] = ($lines[$line] ?? 0) + 1;
            $zones[$zone] = ($zones[$zone] ?? 0) + 1;
        }

        return [
            'avg_maturity' => round($this->avg($maturity), 8),
            'avg_stability' => round($this->avg($stability), 8),
            'avg_affinity' => round($this->avg($affinity), 8),
            'avg_return_pressure' => round($this->avg($returnPressure), 8),
            'avg_anti_bias' => round($this->avg($antiBias), 8),
            'hot_count' => (int) ($temperatures['hot'] ?? 0),
            'neutral_count' => (int) ($temperatures['neutral'] ?? 0),
            'cold_count' => (int) ($temperatures['cold'] ?? 0),
            'temperature_balance' => round($this->temperatureBalance($temperatures), 8),
            'line_balance' => round($this->distributionBalance($lines, 5), 8),
            'zone_balance' => round($this->distributionBalance($zones, 3), 8),
            'entropy' => round($this->entropyScore($lines, 5), 8),
            'max_line_count' => empty($lines) ? 0 : max($lines),
            'max_zone_count' => empty($zones) ? 0 : max($zones),
        ];
    }

    protected function canAcceptBase(array $base, array $profiles, int $quantidadeDezenas, bool $relaxed = false): bool
    {
        $lines = [];
        $zones = [];
        $temperatures = ['hot' => 0, 'neutral' => 0, 'cold' => 0];

        foreach ($base as $number) {
            $line = $this->line($number);
            $zone = $this->zone($number);
            $temperature = $profiles[$number]['temperature'] ?? 'neutral';

            $lines[$line] = ($lines[$line] ?? 0) + 1;
            $zones[$zone] = ($zones[$zone] ?? 0) + 1;
            $temperatures[$temperature] = ($temperatures[$temperature] ?? 0) + 1;
        }

        $lineLimit = $this->lineLimit($quantidadeDezenas) + ($relaxed ? 1 : 0);
        $zoneLimit = $this->zoneLimit($quantidadeDezenas) + ($relaxed ? 1 : 0);
        $temperatureLimits = $this->temperatureLimits($quantidadeDezenas, $relaxed);

        if (! empty($lines) && max($lines) > $lineLimit) {
            return false;
        }

        if (! empty($zones) && max($zones) > $zoneLimit) {
            return false;
        }

        foreach ($temperatureLimits as $temperature => $limit) {
            if (($temperatures[$temperature] ?? 0) > $limit) {
                return false;
            }
        }

        return true;
    }

    protected function applyPatternBias(array $weights, string $strategyName, array $patternContext): array
    {
        $bias = (float) (($patternContext['strategy_bias'][$strategyName] ?? 1.0));

        foreach ($weights as $metric => $weight) {
            $weights[$metric] = (float) $weight * $bias;
        }

        $total = array_sum($weights);

        if ($total <= 0) {
            return $weights;
        }

        foreach ($weights as $metric => $weight) {
            $weights[$metric] = $weight / $total;
        }

        return $weights;
    }

    protected function temperatureQuotas(int $quantidadeDezenas, array $patternContext = []): array
    {
        $bias = $patternContext['temperature_bias'] ?? [];

        if (! empty($bias)) {
            $hot = (int) round($quantidadeDezenas * (float) ($bias['hot'] ?? 0.40));
            $cold = (int) round($quantidadeDezenas * (float) ($bias['cold'] ?? 0.17));
            $neutral = $quantidadeDezenas - $hot - $cold;

            return [
                'hot' => max(3, $hot),
                'neutral' => max(3, $neutral),
                'cold' => max(1, $cold),
            ];
        }

        return match ($quantidadeDezenas) {
            16 => ['hot' => 6, 'neutral' => 7, 'cold' => 3],
            17 => ['hot' => 7, 'neutral' => 7, 'cold' => 3],
            18 => ['hot' => 7, 'neutral' => 8, 'cold' => 3],
            19 => ['hot' => 8, 'neutral' => 8, 'cold' => 3],
            20 => ['hot' => 8, 'neutral' => 9, 'cold' => 3],
            default => ['hot' => 7, 'neutral' => 8, 'cold' => 3],
        };
    }

    protected function uniqueCandidates(array $candidates): array
    {
        $unique = [];

        foreach ($candidates as $candidate) {
            $key = $candidate['key'];

            if (! isset($unique[$key]) || $candidate['fitness'] > $unique[$key]['fitness']) {
                $unique[$key] = $candidate;
            }
        }

        return array_values($unique);
    }

    protected function weakestNumber(array $selected, array $profiles): ?int
    {
        if (empty($selected)) {
            return null;
        }

        usort($selected, fn (int $a, int $b) => ($profiles[$a]['score'] ?? 0.0) <=> ($profiles[$b]['score'] ?? 0.0));

        return (int) $selected[0];
    }

    protected function extractHistoricalDraws(Collection $historico, LotofacilConcurso $concursoBase): array
    {
        $draws = [];

        foreach ($historico as $concurso) {
            $numeroConcurso = $this->resolveContestNumber($concurso);

            if ($numeroConcurso !== null && $numeroConcurso > (int) $concursoBase->concurso) {
                continue;
            }

            $numbers = $this->extractNumbers($concurso);

            if (count($numbers) === 15) {
                $draws[] = $numbers;
            }
        }

        return $draws;
    }

    protected function resolveContestNumber(LotofacilConcurso|array $concurso): ?int
    {
        if (is_array($concurso)) {
            foreach (['concurso', 'numero', 'id'] as $field) {
                if (isset($concurso[$field]) && is_numeric($concurso[$field])) {
                    return (int) $concurso[$field];
                }
            }

            return null;
        }

        return isset($concurso->concurso) ? (int) $concurso->concurso : null;
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

    protected function averageCorrelation(int $number, array $pairScores): float
    {
        $total = 0.0;
        $count = 0;

        foreach (range(1, 25) as $other) {
            if ($other === $number) {
                continue;
            }

            $total += (float) ($pairScores[$number][$other] ?? $pairScores[$other][$number] ?? 0.0);
            $count++;
        }

        if ($count === 0) {
            return 0.5;
        }

        return $this->sigmoid($total / $count);
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

            $total += (float) ($pairScores[$number][$other] ?? $pairScores[$other][$number] ?? 0.0);
            $count++;
        }

        if ($count === 0) {
            return 0.5;
        }

        return $this->sigmoid($total / $count);
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

    protected function controlledRotationBonus(int $number, LotofacilConcurso $concursoBase, int $quantidadeDezenas): float
    {
        $seed = (((int) $concursoBase->concurso * 19) + ($number * 23) + ($quantidadeDezenas * 11)) % 100;

        return match (true) {
            $seed <= 8 => -0.008,
            $seed <= 22 => -0.004,
            $seed <= 68 => 0.0,
            $seed <= 86 => 0.004,
            default => 0.008,
        };
    }

    protected function extremeBiasPenalty(float $frequency, float $delay, float $cycle): float
    {
        $penalty = 0.0;

        if ($frequency >= 0.94 && $delay <= 0.06) {
            $penalty += 0.025;
        }

        if ($frequency <= 0.05 && $delay >= 0.95) {
            $penalty += 0.025;
        }

        if ($cycle <= 0.04 || $cycle >= 0.96) {
            $penalty += 0.015;
        }

        return $penalty;
    }

    protected function temperature(float $frequency, float $delay, float $cycle): string
    {
        $temperatureScore = ($frequency * 0.55) + ((1.0 - $delay) * 0.25) + ($cycle * 0.20);

        if ($temperatureScore >= 0.66) {
            return 'hot';
        }

        if ($temperatureScore <= 0.38) {
            return 'cold';
        }

        return 'neutral';
    }

    protected function temperatureLimits(int $quantidadeDezenas, bool $relaxed = false): array
    {
        $extra = $relaxed ? 1 : 0;

        return match ($quantidadeDezenas) {
            16 => ['hot' => 9 + $extra, 'neutral' => 11 + $extra, 'cold' => 6 + $extra],
            17 => ['hot' => 10 + $extra, 'neutral' => 11 + $extra, 'cold' => 6 + $extra],
            18 => ['hot' => 11 + $extra, 'neutral' => 12 + $extra, 'cold' => 7 + $extra],
            19 => ['hot' => 11 + $extra, 'neutral' => 12 + $extra, 'cold' => 7 + $extra],
            20 => ['hot' => 12 + $extra, 'neutral' => 13 + $extra, 'cold' => 8 + $extra],
            default => ['hot' => 11 + $extra, 'neutral' => 12 + $extra, 'cold' => 7 + $extra],
        };
    }

    protected function lineLimit(int $quantidadeDezenas): int
    {
        return match ($quantidadeDezenas) {
            16 => 4,
            17 => 5,
            18 => 5,
            19 => 6,
            20 => 6,
            default => 5,
        };
    }

    protected function zoneLimit(int $quantidadeDezenas): int
    {
        return match ($quantidadeDezenas) {
            16 => 8,
            17 => 8,
            18 => 9,
            19 => 9,
            20 => 10,
            default => 9,
        };
    }

    protected function temperatureBalance(array $temperatures): float
    {
        $total = array_sum($temperatures);

        if ($total <= 0) {
            return 0.0;
        }

        $hot = ($temperatures['hot'] ?? 0) / $total;
        $neutral = ($temperatures['neutral'] ?? 0) / $total;
        $cold = ($temperatures['cold'] ?? 0) / $total;

        $distance = abs($hot - 0.42) + abs($neutral - 0.41) + abs($cold - 0.17);

        return max(0.0, 1.0 - ($distance / 1.20));
    }

    protected function distributionBalance(array $distribution, int $buckets): float
    {
        $total = array_sum($distribution);

        if ($total <= 0 || $buckets <= 0) {
            return 0.0;
        }

        $expected = $total / $buckets;
        $distance = 0.0;

        for ($i = 1; $i <= $buckets; $i++) {
            $distance += abs(($distribution[$i] ?? 0) - $expected);
        }

        return max(0.0, 1.0 - ($distance / max(1.0, $total)));
    }

    protected function entropyScore(array $distribution, int $buckets): float
    {
        $total = array_sum($distribution);

        if ($total <= 0 || $buckets <= 1) {
            return 0.0;
        }

        $entropy = 0.0;

        foreach ($distribution as $count) {
            if ($count <= 0) {
                continue;
            }

            $p = $count / $total;
            $entropy -= $p * log($p);
        }

        return max(0.0, min(1.0, $entropy / log($buckets)));
    }

    protected function avg(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }

        return array_sum($values) / count($values);
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

    protected function extractNumbers(LotofacilConcurso|array $concurso): array
    {
        if (is_array($concurso)) {
            if (isset($concurso['dezenas']) && is_array($concurso['dezenas'])) {
                return collect($concurso['dezenas'])
                    ->map(fn ($n) => (int) $n)
                    ->filter(fn ($n) => $n > 0)
                    ->unique()
                    ->sort()
                    ->values()
                    ->toArray();
            }

            $numbers = [];

            for ($i = 1; $i <= 15; $i++) {
                $field = 'bola' . $i;

                if (isset($concurso[$field]) && is_numeric($concurso[$field])) {
                    $numbers[] = (int) $concurso[$field];
                }
            }

            if (count($numbers) === 15) {
                return collect($numbers)
                    ->filter(fn ($n) => $n > 0)
                    ->unique()
                    ->sort()
                    ->values()
                    ->toArray();
            }

            return collect($concurso)
                ->filter(fn ($value, $key) => is_numeric($value) && preg_match('/^(bola\d+|dezena\d+|d\d+)$/', (string) $key))
                ->map(fn ($n) => (int) $n)
                ->filter(fn ($n) => $n > 0 && $n <= 25)
                ->unique()
                ->sort()
                ->values()
                ->toArray();
        }

        $numbers = [];

        for ($i = 1; $i <= 15; $i++) {
            $field = 'bola' . $i;

            if (isset($concurso->{$field})) {
                $numbers[] = (int) $concurso->{$field};
            }
        }

        return collect($numbers)
            ->filter(fn ($n) => $n > 0)
            ->unique()
            ->sort()
            ->values()
            ->toArray();
    }

    protected function sigmoid(float $value): float
    {
        return 1.0 / (1.0 + exp(-$value));
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
}
