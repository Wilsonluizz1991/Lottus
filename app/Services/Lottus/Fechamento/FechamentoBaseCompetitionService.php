<?php

namespace App\Services\Lottus\Fechamento;

use App\Models\LotofacilConcurso;
use App\Models\LottusLearningSnapshot;
use App\Services\Lottus\Learning\Scoring\FechamentoBaseDecisionService;
use Illuminate\Support\Collection;

class FechamentoBaseCompetitionService
{
    protected array $lastNumberScores = [];
    protected array $lastCompetitionReport = [];

    public function __construct(
        protected FechamentoAffinityClusterService $affinityClusterService,
        protected FechamentoBaseDecisionService $decisionService
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
        $bases = $this->selectTopBases(
            primaryBase: $primaryBase,
            quantidadeDezenas: $quantidadeDezenas,
            historico: $historico,
            frequencyContext: $frequencyContext,
            delayContext: $delayContext,
            correlationContext: $correlationContext,
            structureContext: $structureContext,
            cycleContext: $cycleContext,
            concursoBase: $concursoBase,
            patternContext: $patternContext,
            limit: 1
        );

        return $bases[0] ?? $this->normalizeNumbers($primaryBase);
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

        $historicalContests = $this->extractHistoricalContests($historico, $concursoBase);

        if (count($historicalContests) < 180) {
            return [$primaryBase];
        }

        $currentProfiles = $this->buildCurrentNumberProfiles(
            frequencyContext: $frequencyContext,
            delayContext: $delayContext,
            correlationContext: $correlationContext,
            cycleContext: $cycleContext,
            concursoBase: $concursoBase
        );

        $learningSnapshot = $this->activeLearningSnapshot($concursoBase);
        $currentProfiles = $this->applyLearningSnapshotToProfiles($currentProfiles, $learningSnapshot, $quantidadeDezenas);

        $this->lastNumberScores = $currentProfiles;

        $strategyDefinitions = $this->applyLearningSnapshotToStrategyDefinitions(
            strategyDefinitions: $this->strategyDefinitions(),
            learningSnapshot: $learningSnapshot,
            quantidadeDezenas: $quantidadeDezenas
        );
        $walkForwardReport = $this->runWalkForwardTournament(
            historicalContests: $historicalContests,
            quantidadeDezenas: $quantidadeDezenas,
            strategyDefinitions: $strategyDefinitions
        );

        $candidates = [];

        $candidates[] = $this->makeCurrentCandidate(
            strategy: 'selector_primary',
            numbers: $primaryBase,
            profiles: $currentProfiles,
            historicalContests: $historicalContests,
            quantidadeDezenas: $quantidadeDezenas,
            walkForwardMetrics: $walkForwardReport['strategies']['selector_primary'] ?? []
        );

        foreach ($strategyDefinitions as $strategyName => $weights) {
            $base = $this->buildBaseFromProfiles(
                profiles: $currentProfiles,
                quantidadeDezenas: $quantidadeDezenas,
                weights: $this->applyPatternBias($weights, $patternContext),
                forcedNumbers: [],
                blockedNumbers: [],
                salt: crc32($strategyName . '|' . $concursoBase->concurso)
            );

            if (count($base) !== $quantidadeDezenas) {
                continue;
            }

            $candidates[] = $this->makeCurrentCandidate(
                strategy: $strategyName,
                numbers: $base,
                profiles: $currentProfiles,
                historicalContests: $historicalContests,
                quantidadeDezenas: $quantidadeDezenas,
                walkForwardMetrics: $walkForwardReport['strategies'][$strategyName] ?? []
            );
        }

        $candidates = $this->addWalkForwardHybridCandidates(
            candidates: $candidates,
            strategyDefinitions: $strategyDefinitions,
            walkForwardReport: $walkForwardReport,
            currentProfiles: $currentProfiles,
            historicalContests: $historicalContests,
            quantidadeDezenas: $quantidadeDezenas,
            concursoBase: $concursoBase
        );

        $candidates = $this->addDiversityRescueCandidates(
            candidates: $candidates,
            currentProfiles: $currentProfiles,
            historicalContests: $historicalContests,
            quantidadeDezenas: $quantidadeDezenas,
            concursoBase: $concursoBase,
            patternContext: $patternContext
        );

        $candidates = $this->addLearningSnapshotCandidates(
            candidates: $candidates,
            currentProfiles: $currentProfiles,
            historicalContests: $historicalContests,
            quantidadeDezenas: $quantidadeDezenas,
            concursoBase: $concursoBase,
            learningSnapshot: $learningSnapshot
        );

        $candidates = $this->uniqueCandidates($candidates);

        $candidates = $this->attachSimulationMetricsToCandidates(
            candidates: $candidates,
            historicalContests: $historicalContests,
            quantidadeDezenas: $quantidadeDezenas
        );

        $candidates = $this->applyLearningSnapshotToCandidates(
            candidates: $candidates,
            learningSnapshot: $learningSnapshot,
            quantidadeDezenas: $quantidadeDezenas
        );

        $peakCandidates = array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => (int) ($candidate['simulation']['max_hits'] ?? 0) >= 14
        ));

        $rankingPool = ! empty($peakCandidates) ? $peakCandidates : $candidates;

        usort($rankingPool, function (array $a, array $b): int {
            $aMaxHits = (int) ($a['simulation']['max_hits'] ?? 0);
            $bMaxHits = (int) ($b['simulation']['max_hits'] ?? 0);

            if (($aMaxHits >= 14) !== ($bMaxHits >= 14)) {
                return $aMaxHits >= 14 ? -1 : 1;
            }

            if (($aMaxHits >= 13) !== ($bMaxHits >= 13)) {
                return $aMaxHits >= 13 ? -1 : 1;
            }

            if (($a['simulation_score'] ?? 0.0) !== ($b['simulation_score'] ?? 0.0)) {
                return ($b['simulation_score'] ?? 0.0) <=> ($a['simulation_score'] ?? 0.0);
            }

            if ($aMaxHits !== $bMaxHits) {
                return $bMaxHits <=> $aMaxHits;
            }

            if (($a['fitness'] ?? 0.0) === ($b['fitness'] ?? 0.0)) {
                return strcmp($a['key'] ?? '', $b['key'] ?? '');
            }

            return ($b['fitness'] ?? 0.0) <=> ($a['fitness'] ?? 0.0);
        });

        $decisionCandidates = array_slice($rankingPool, 0, max($limit * 4, 24));

        $decision = $this->decisionService->decide(
            bases: array_map(fn (array $candidate): array => $candidate['numbers'], $decisionCandidates),
            quantidadeDezenas: $quantidadeDezenas,
            historico: $historico,
            concursoBase: $concursoBase,
            limit: $limit
        );

        $candidates = $this->composeRankedOutputCandidates(
            rankingPool: $rankingPool,
            limit: $limit,
            reserveRepeatSurvival: true
        );

        if (empty($candidates)) {
            $candidates = [[
                'strategy' => 'fallback_primary',
                'numbers' => $primaryBase,
                'key' => $this->key($primaryBase),
                'fitness' => 0.0,
                'profile' => [],
                'walk_forward' => [],
                'robustness' => [],
            ]];
        }

        $this->lastCompetitionReport = [
            'concurso' => $concursoBase->concurso,
            'quantidade_dezenas' => $quantidadeDezenas,
            'winner' => $candidates[0],
            'candidates' => $candidates,
            'learning_decision' => $decision ?? [],
            'walk_forward_report' => $walkForwardReport,
            'pattern_regime' => $patternContext['regime'] ?? null,
            'pattern_confidence' => $patternContext['confidence'] ?? null,
            'peak_filter_applied' => ! empty($peakCandidates),
            'peak_candidates_count' => count($peakCandidates),
            'learning_snapshot_id' => $learningSnapshot?->id,
            'learning_snapshot_version' => $learningSnapshot?->calibration_version,
            'learning_snapshot_validation_status' => $learningSnapshot?->validation_status,
            'learning_snapshot_promoted_strategy' => $learningSnapshot?->promoted_strategy,
        ];

        logger()->info('FECHAMENTO_BASE_COMPETITION_LEARNING_DECISION', $this->lastCompetitionReport);

        return array_map(
            fn (array $candidate): array => $candidate['numbers'],
            $candidates
        );
    }

    public function getLastNumberScores(): array
    {
        return $this->lastNumberScores;
    }

    protected function composeRankedOutputCandidates(
        array $rankingPool,
        int $limit,
        bool $reserveRepeatSurvival
    ): array {
        if (! $reserveRepeatSurvival || $limit < 3) {
            return array_slice($rankingPool, 0, $limit);
        }

        $repeatSurvivalCandidates = array_values(array_filter(
            $rankingPool,
            fn (array $candidate): bool => str_starts_with((string) ($candidate['strategy'] ?? ''), 'repeat_survival_')
        ));

        if (empty($repeatSurvivalCandidates)) {
            return array_slice($rankingPool, 0, $limit);
        }

        usort($repeatSurvivalCandidates, function (array $a, array $b): int {
            $aPriority = $this->repeatSurvivalPriority((string) ($a['strategy'] ?? ''));
            $bPriority = $this->repeatSurvivalPriority((string) ($b['strategy'] ?? ''));

            if ($aPriority !== $bPriority) {
                return $bPriority <=> $aPriority;
            }

            if (($a['simulation_score'] ?? 0.0) !== ($b['simulation_score'] ?? 0.0)) {
                return ($b['simulation_score'] ?? 0.0) <=> ($a['simulation_score'] ?? 0.0);
            }

            return ($b['fitness'] ?? 0.0) <=> ($a['fitness'] ?? 0.0);
        });

        $reservedCount = min(3, max(1, (int) floor($limit / 2)), count($repeatSurvivalCandidates));
        $selected = array_slice($repeatSurvivalCandidates, 0, $reservedCount);
        $selectedKeys = array_fill_keys(array_map(fn (array $candidate): string => (string) ($candidate['key'] ?? ''), $selected), true);

        foreach ($rankingPool as $candidate) {
            if (count($selected) >= $limit) {
                break;
            }

            $key = (string) ($candidate['key'] ?? '');

            if (isset($selectedKeys[$key])) {
                continue;
            }

            $selected[] = $candidate;
            $selectedKeys[$key] = true;
        }

        return array_slice($selected, 0, $limit);
    }

    protected function repeatSurvivalPriority(string $strategy): float
    {
        if (! preg_match('/_(\d+)_(\d+)$/', $strategy, $matches)) {
            return 0.0;
        }

        $repeatTarget = (int) $matches[1];
        $highCount = (int) $matches[2];
        $targetHighCount = max(2, (int) round($repeatTarget * 0.30));

        return 100.0
            - (abs(11 - $repeatTarget) * 10.0)
            - (abs($targetHighCount - $highCount) * 4.0);
    }

    public function getLastCompetitionReport(): array
    {
        return $this->lastCompetitionReport;
    }

    protected function activeLearningSnapshot(LotofacilConcurso $concursoBase): ?LottusLearningSnapshot
    {
        if (! (bool) config('lottus_fechamento.learning_snapshots.enabled', true)) {
            return null;
        }

        $validationSnapshotId = config('lottus_fechamento.learning_snapshots.validation_snapshot_id');

        if ($validationSnapshotId) {
            return LottusLearningSnapshot::query()
                ->whereKey((int) $validationSnapshotId)
                ->where('concurso', (int) $concursoBase->concurso)
                ->where('target_concurso', ((int) $concursoBase->concurso) + 1)
                ->first();
        }

        return LottusLearningSnapshot::query()
            ->where('concurso', (int) $concursoBase->concurso)
            ->where('target_concurso', ((int) $concursoBase->concurso) + 1)
            ->orderByDesc('calibration_version')
            ->first();
    }

    protected function applyLearningSnapshotToProfiles(
        array $profiles,
        ?LottusLearningSnapshot $learningSnapshot,
        int $quantidadeDezenas
    ): array
    {
        return $profiles;
    }

    protected function applyLearningSnapshotToStrategyDefinitions(
        array $strategyDefinitions,
        ?LottusLearningSnapshot $learningSnapshot,
        int $quantidadeDezenas
    ): array {
        return $strategyDefinitions;
    }

    protected function applyLearningSnapshotToCandidates(
        array $candidates,
        ?LottusLearningSnapshot $learningSnapshot,
        int $quantidadeDezenas
    ): array {
        if (
            ! $learningSnapshot
            || ! $this->snapshotHasEliteSignal($learningSnapshot, $quantidadeDezenas)
            || ! $this->shouldApplyLearningRanking($learningSnapshot)
        ) {
            return $candidates;
        }

        $pairBias = $learningSnapshot->pair_bias ?? [];
        $structureBias = $learningSnapshot->structure_bias[(string) $quantidadeDezenas] ?? [];
        $rawElite = $learningSnapshot->raw_elite_protection[(string) $quantidadeDezenas] ?? [];

        foreach ($candidates as &$candidate) {
            $numbers = $this->normalizeNumbers($candidate['numbers'] ?? []);
            $pairScore = $this->pairBiasScore($numbers, $pairBias);
            $structureScore = $this->structureBiasScore($numbers, $structureBias);
            $eliteScore = $this->rawEliteSnapshotScore($rawElite);

            $learningScore = round(
                ($pairScore * 24.0) +
                ($structureScore * 14.0) +
                ($eliteScore * 18.0),
                8
            );

            $candidate['learning_snapshot_score'] = $learningScore;
            $candidate['fitness'] = round(((float) ($candidate['fitness'] ?? 0.0)) + $learningScore, 8);
            $candidate['simulation_score'] = round(((float) ($candidate['simulation_score'] ?? 0.0)) + ($learningScore * 0.45), 8);
        }

        unset($candidate);

        return $candidates;
    }

    protected function numberBiasFromPairBias(array $pairBias): array
    {
        if (empty($pairBias)) {
            return [];
        }

        $max = max(1, max(array_map('intval', $pairBias)));
        $scores = array_fill_keys(range(1, 25), 0.0);

        foreach ($pairBias as $pair => $count) {
            $parts = array_map('intval', explode('-', (string) $pair));

            if (count($parts) !== 2) {
                continue;
            }

            $value = ((int) $count) / $max;
            $scores[$parts[0]] = ($scores[$parts[0]] ?? 0.0) + $value;
            $scores[$parts[1]] = ($scores[$parts[1]] ?? 0.0) + $value;
        }

        $scoreMax = max(1.0, max($scores));

        foreach ($scores as $number => $score) {
            $scores[$number] = round($score / $scoreMax, 8);
        }

        return $scores;
    }

    protected function pairBiasScore(array $numbers, array $pairBias): float
    {
        $numbers = $this->normalizeNumbers($numbers);

        if (count($numbers) < 2 || empty($pairBias)) {
            return 0.0;
        }

        $max = max(1, max(array_map('intval', $pairBias)));
        $score = 0.0;
        $pairs = 0;

        for ($i = 0; $i < count($numbers); $i++) {
            for ($j = $i + 1; $j < count($numbers); $j++) {
                $key = $numbers[$i] . '-' . $numbers[$j];
                $score += ((int) ($pairBias[$key] ?? 0)) / $max;
                $pairs++;
            }
        }

        return $pairs > 0 ? max(0.0, min(1.0, $score / $pairs)) : 0.0;
    }

    protected function structureBiasScore(array $numbers, array $structureBias): float
    {
        $numbers = $this->normalizeNumbers($numbers);

        if (empty($numbers) || empty($structureBias)) {
            return 0.0;
        }

        $avgBaseSum = (float) ($structureBias['avg_base_sum'] ?? 0.0);
        $avgOddCount = (float) ($structureBias['avg_odd_count'] ?? 0.0);
        $maxHits = (int) ($structureBias['max_hits'] ?? 0);

        $sumScore = $avgBaseSum > 0.0
            ? max(0.0, 1.0 - (abs(array_sum($numbers) - $avgBaseSum) / 80.0))
            : 0.0;

        $oddCount = count(array_filter($numbers, fn (int $number): bool => $number % 2 !== 0));
        $oddScore = $avgOddCount > 0.0
            ? max(0.0, 1.0 - (abs($oddCount - $avgOddCount) / 8.0))
            : 0.0;

        $peakScore = $maxHits >= 14 ? 1.0 : ($maxHits >= 13 ? 0.55 : 0.20);

        return max(0.0, min(1.0, ($sumScore * 0.35) + ($oddScore * 0.25) + ($peakScore * 0.40)));
    }

    protected function rawEliteSnapshotScore(array $rawElite): float
    {
        if (empty($rawElite)) {
            return 0.0;
        }

        $samples = max(1, (int) ($rawElite['samples'] ?? 1));
        $raw13 = (int) ($rawElite['raw_13_plus'] ?? 0);
        $raw14 = (int) ($rawElite['raw_14_plus'] ?? 0);
        $raw15 = (int) ($rawElite['raw_15'] ?? 0);
        $maxHits = (int) ($rawElite['max_hits'] ?? 0);

        return max(0.0, min(1.0,
            (($raw13 / $samples) * 0.25) +
            (($raw14 / $samples) * 0.45) +
            (($raw15 / $samples) * 0.20) +
            ($maxHits >= 14 ? 0.10 : 0.0)
        ));
    }

    protected function snapshotHasEliteSignal(LottusLearningSnapshot $learningSnapshot, int $quantidadeDezenas): bool
    {
        $rawElite = $learningSnapshot->raw_elite_protection[(string) $quantidadeDezenas] ?? [];

        if (empty($rawElite) || ! is_array($rawElite)) {
            return false;
        }

        return ((int) ($rawElite['max_hits'] ?? 0)) >= 14
            || ((int) ($rawElite['raw_14_plus'] ?? 0)) > 0
            || ((int) ($rawElite['raw_15'] ?? 0)) > 0;
    }

    protected function shouldApplyLearningRanking(LottusLearningSnapshot $learningSnapshot): bool
    {
        if ((bool) config('lottus_fechamento.learning_snapshots.validation_mode', false)) {
            return (bool) config('lottus_fechamento.learning_snapshots.affect_ranking', false);
        }

        if (
            (bool) config('lottus_fechamento.learning_snapshots.use_promoted', true)
            && $this->snapshotPromotedFor($learningSnapshot, [
                LottusLearningSnapshot::STRATEGY_RANKING,
                LottusLearningSnapshot::STRATEGY_COMBINED,
            ])
        ) {
            return true;
        }

        return (bool) config('lottus_fechamento.learning_snapshots.allow_unvalidated_effects', false)
            && (bool) config('lottus_fechamento.learning_snapshots.affect_ranking', false);
    }

    protected function shouldGenerateLearningCandidates(LottusLearningSnapshot $learningSnapshot, int $quantidadeDezenas): bool
    {
        if (! $this->snapshotHasEliteSignal($learningSnapshot, $quantidadeDezenas)) {
            return false;
        }

        if ((bool) config('lottus_fechamento.learning_snapshots.validation_mode', false)) {
            return (bool) config('lottus_fechamento.learning_snapshots.generate_candidates', false);
        }

        if (
            (bool) config('lottus_fechamento.learning_snapshots.use_promoted', true)
            && $this->snapshotPromotedFor($learningSnapshot, [
                LottusLearningSnapshot::STRATEGY_CANDIDATES,
                LottusLearningSnapshot::STRATEGY_COMBINED,
            ])
        ) {
            return true;
        }

        return (bool) config('lottus_fechamento.learning_snapshots.allow_unvalidated_effects', false)
            && (bool) config('lottus_fechamento.learning_snapshots.generate_candidates', false);
    }

    protected function snapshotPromotedFor(LottusLearningSnapshot $learningSnapshot, array $strategies): bool
    {
        return $learningSnapshot->validation_status === LottusLearningSnapshot::VALIDATION_PROMOTED
            && in_array((string) $learningSnapshot->promoted_strategy, $strategies, true);
    }

    protected function strategyDefinitions(): array
    {
        return [
            'balanced_predictive' => [
                'frequency' => 0.16,
                'delay' => 0.18,
                'cycle' => 0.20,
                'correlation' => 0.20,
                'recent_presence' => 0.04,
                'return_pressure' => 0.18,
                'stability' => 0.04,
            ],
            'medium_window_strength' => [
                'frequency' => 0.18,
                'delay' => 0.16,
                'cycle' => 0.18,
                'correlation' => 0.22,
                'recent_presence' => 0.06,
                'return_pressure' => 0.16,
                'stability' => 0.04,
            ],
            'long_window_stability' => [
                'frequency' => 0.20,
                'delay' => 0.14,
                'cycle' => 0.16,
                'correlation' => 0.22,
                'recent_presence' => 0.04,
                'return_pressure' => 0.14,
                'stability' => 0.10,
            ],
            'return_pressure' => [
                'frequency' => 0.10,
                'delay' => 0.26,
                'cycle' => 0.24,
                'correlation' => 0.12,
                'recent_presence' => 0.02,
                'return_pressure' => 0.24,
                'stability' => 0.02,
            ],
            'anti_recency_bias' => [
                'frequency' => 0.14,
                'delay' => 0.20,
                'cycle' => 0.22,
                'correlation' => 0.16,
                'recent_presence' => 0.01,
                'return_pressure' => 0.23,
                'stability' => 0.04,
            ],
            'correlation_blocks' => [
                'frequency' => 0.12,
                'delay' => 0.12,
                'cycle' => 0.14,
                'correlation' => 0.40,
                'recent_presence' => 0.04,
                'return_pressure' => 0.14,
                'stability' => 0.04,
            ],
            'peak_correlation_hunt' => [
                'frequency' => 0.08,
                'delay' => 0.12,
                'cycle' => 0.14,
                'correlation' => 0.50,
                'recent_presence' => 0.02,
                'return_pressure' => 0.12,
                'stability' => 0.02,
            ],
            'fourteen_ceiling_hunt' => [
                'frequency' => 0.06,
                'delay' => 0.18,
                'cycle' => 0.20,
                'correlation' => 0.36,
                'recent_presence' => 0.01,
                'return_pressure' => 0.18,
                'stability' => 0.01,
            ],
            'rupture_controlled' => [
                'frequency' => 0.10,
                'delay' => 0.24,
                'cycle' => 0.24,
                'correlation' => 0.16,
                'recent_presence' => 0.01,
                'return_pressure' => 0.23,
                'stability' => 0.02,
            ],
            'hot_neutral_balance' => [
                'frequency' => 0.18,
                'delay' => 0.16,
                'cycle' => 0.18,
                'correlation' => 0.20,
                'recent_presence' => 0.06,
                'return_pressure' => 0.16,
                'stability' => 0.06,
            ],
        ];
    }

    protected function runWalkForwardTournament(
        array $historicalContests,
        int $quantidadeDezenas,
        array $strategyDefinitions
    ): array {
        $minTrainingSize = 160;
        $evaluationWindow = min(120, max(40, count($historicalContests) - $minTrainingSize - 1));
        $startIndex = max($minTrainingSize, count($historicalContests) - $evaluationWindow);
        $strategyMetrics = [];

        $strategyNames = array_merge(['selector_primary'], array_keys($strategyDefinitions));

        foreach ($strategyNames as $strategyName) {
            $strategyMetrics[$strategyName] = [
                'strategy' => $strategyName,
                'samples' => 0,
                'total_hits' => 0,
                'avg_hits' => 0.0,
                'min_hits' => 15,
                'max_hits' => 0,
                'coverage_11' => 0,
                'coverage_12' => 0,
                'coverage_13' => 0,
                'coverage_14' => 0,
                'score' => 0.0,
                'recent_score' => 0.0,
                'stability' => 0.0,
                'missed_critical_numbers' => [],
                'hit_distribution' => [],
            ];
        }

        for ($index = $startIndex; $index < count($historicalContests); $index++) {
            $trainingContests = array_slice($historicalContests, 0, $index);
            $targetContest = $historicalContests[$index];
            $targetNumbers = $targetContest['numbers'] ?? [];

            if (count($trainingContests) < $minTrainingSize || count($targetNumbers) !== 15) {
                continue;
            }

            $trainingProfiles = $this->buildProfilesFromContests($trainingContests);
            $selectorPrimary = $this->buildBaseFromProfiles(
                profiles: $trainingProfiles,
                quantidadeDezenas: $quantidadeDezenas,
                weights: $strategyDefinitions['balanced_predictive'],
                forcedNumbers: [],
                blockedNumbers: [],
                salt: (int) ($targetContest['concurso'] ?? $index)
            );

            $this->recordWalkForwardResult(
                metrics: $strategyMetrics['selector_primary'],
                base: $selectorPrimary,
                targetNumbers: $targetNumbers,
                recentWeight: $this->recentEvaluationWeight($index, count($historicalContests))
            );

            foreach ($strategyDefinitions as $strategyName => $weights) {
                $base = $this->buildBaseFromProfiles(
                    profiles: $trainingProfiles,
                    quantidadeDezenas: $quantidadeDezenas,
                    weights: $weights,
                    forcedNumbers: [],
                    blockedNumbers: [],
                    salt: crc32($strategyName . '|' . ($targetContest['concurso'] ?? $index))
                );

                $this->recordWalkForwardResult(
                    metrics: $strategyMetrics[$strategyName],
                    base: $base,
                    targetNumbers: $targetNumbers,
                    recentWeight: $this->recentEvaluationWeight($index, count($historicalContests))
                );
            }
        }

        foreach ($strategyMetrics as $strategyName => &$metrics) {
            $samples = max(1, (int) $metrics['samples']);
            $metrics['avg_hits'] = round($metrics['total_hits'] / $samples, 8);
            $metrics['coverage_11_rate'] = round($metrics['coverage_11'] / $samples, 8);
            $metrics['coverage_12_rate'] = round($metrics['coverage_12'] / $samples, 8);
            $metrics['coverage_13_rate'] = round($metrics['coverage_13'] / $samples, 8);
            $metrics['coverage_14_rate'] = round($metrics['coverage_14'] / $samples, 8);
            $metrics['stability'] = $this->walkForwardStability($metrics['hit_distribution']);
            $peakScore = $this->walkForwardPeakScore($metrics['hit_distribution']);
            $metrics['peak_score'] = round($peakScore, 8);
            $metrics['score'] = round(
                ($metrics['avg_hits'] * 2.0) +
                ($metrics['max_hits'] * 22.0) +
                ($peakScore * 38.0) +
                ($metrics['coverage_11_rate'] * 5.0) +
                ($metrics['coverage_12_rate'] * 8.0) +
                ($metrics['coverage_13_rate'] * 42.0) +
                ($metrics['coverage_14_rate'] * 140.0) +
                ($metrics['recent_score'] * 8.0) +
                ($metrics['stability'] * 1.5) -
                (max(0, 13 - $metrics['max_hits']) * 18.0) -
                (max(0, 11 - $metrics['min_hits']) * 0.8),
                8
            );
        }

        unset($metrics);

        uasort($strategyMetrics, function (array $a, array $b): int {
            if (($a['score'] ?? 0.0) === ($b['score'] ?? 0.0)) {
                return strcmp($a['strategy'] ?? '', $b['strategy'] ?? '');
            }

            return ($b['score'] ?? 0.0) <=> ($a['score'] ?? 0.0);
        });

        return [
            'samples' => $evaluationWindow,
            'best_strategy' => array_key_first($strategyMetrics),
            'strategies' => $strategyMetrics,
        ];
    }

    protected function walkForwardPeakScore(array $hitDistribution): float
    {
        if (empty($hitDistribution)) {
            return 0.0;
        }

        $score = 0.0;

        foreach ($hitDistribution as $hits) {
            $hits = (int) $hits;

            if ($hits >= 15) {
                $score += 36.0;
            } elseif ($hits >= 14) {
                $score += 18.0;
            } elseif ($hits >= 13) {
                $score += 6.0;
            } elseif ($hits >= 12) {
                $score += 1.2;
            } elseif ($hits <= 9) {
                $score -= 0.6;
            }
        }

        return $score / max(1, count($hitDistribution));
    }

    protected function recordWalkForwardResult(
        array &$metrics,
        array $base,
        array $targetNumbers,
        float $recentWeight
    ): void {
        $base = $this->normalizeNumbers($base);
        $targetNumbers = $this->normalizeNumbers($targetNumbers);

        if (empty($base) || count($targetNumbers) !== 15) {
            return;
        }

        $hits = count(array_intersect($base, $targetNumbers));
        $misses = array_values(array_diff($targetNumbers, $base));

        $metrics['samples']++;
        $metrics['total_hits'] += $hits;
        $metrics['min_hits'] = min($metrics['min_hits'], $hits);
        $metrics['max_hits'] = max($metrics['max_hits'], $hits);
        $metrics['recent_score'] += ($hits / 15) * $recentWeight;
        $metrics['hit_distribution'][] = $hits;

        if ($hits >= 11) {
            $metrics['coverage_11']++;
        }

        if ($hits >= 12) {
            $metrics['coverage_12']++;
        }

        if ($hits >= 13) {
            $metrics['coverage_13']++;
        }

        if ($hits >= 14) {
            $metrics['coverage_14']++;
        }

        foreach ($misses as $number) {
            $metrics['missed_critical_numbers'][$number] = ($metrics['missed_critical_numbers'][$number] ?? 0) + 1;
        }
    }

    protected function makeCurrentCandidate(
        string $strategy,
        array $numbers,
        array $profiles,
        array $historicalContests,
        int $quantidadeDezenas,
        array $walkForwardMetrics
    ): array {
        $numbers = $this->normalizeNumbers($numbers);
        $profile = $this->baseProfile($numbers, $profiles);
        $robustness = $this->baseHistoricalRobustness($numbers, $historicalContests);

        $walkScore = (float) ($walkForwardMetrics['score'] ?? 0.0);
        $walkAvgHits = (float) ($walkForwardMetrics['avg_hits'] ?? 0.0);
        $walkCoverage11 = (float) ($walkForwardMetrics['coverage_11_rate'] ?? 0.0);
        $walkCoverage12 = (float) ($walkForwardMetrics['coverage_12_rate'] ?? 0.0);
        $walkCoverage13 = (float) ($walkForwardMetrics['coverage_13_rate'] ?? 0.0);
        $walkCoverage14 = (float) ($walkForwardMetrics['coverage_14_rate'] ?? 0.0);
        $walkStability = (float) ($walkForwardMetrics['stability'] ?? 0.0);
        $walkPeakScore = (float) ($walkForwardMetrics['peak_score'] ?? 0.0);
        $walkMaxHits = (float) ($walkForwardMetrics['max_hits'] ?? 0.0);

        $fitness =
            ($walkScore * 5.0) +
            ($walkAvgHits * 2.0) +
            ($walkMaxHits * 28.0) +
            ($walkPeakScore * 46.0) +
            ($walkCoverage11 * 5.0) +
            ($walkCoverage12 * 8.0) +
            ($walkCoverage13 * 46.0) +
            ($walkCoverage14 * 180.0) +
            ($walkStability * 1.5) +
            (($robustness['avg_hits'] ?? 0.0) * 1.5) +
            (($robustness['max_hits'] ?? 0.0) * 18.0) +
            (($robustness['coverage_11'] ?? 0.0) * 4.0) +
            (($robustness['coverage_12'] ?? 0.0) * 7.0) +
            (($robustness['coverage_13'] ?? 0.0) * 42.0) +
            (($robustness['coverage_14'] ?? 0.0) * 160.0) +
            (($profile['entropy'] ?? 0.0) * 3.0) +
            (($profile['line_balance'] ?? 0.0) * 2.0) +
            (($profile['zone_balance'] ?? 0.0) * 2.0) -
            (($profile['concentration_penalty'] ?? 0.0) * 2.0);

        if ($this->isBaseCommerciallyFragile($numbers, $profiles, $quantidadeDezenas)) {
            $fitness -= 2.0;
        }

        return [
            'strategy' => $strategy,
            'numbers' => $numbers,
            'key' => $this->key($numbers),
            'fitness' => round($fitness, 8),
            'profile' => $profile,
            'robustness' => $robustness,
            'walk_forward' => $walkForwardMetrics,
        ];
    }

    protected function addWalkForwardHybridCandidates(
        array $candidates,
        array $strategyDefinitions,
        array $walkForwardReport,
        array $currentProfiles,
        array $historicalContests,
        int $quantidadeDezenas,
        LotofacilConcurso $concursoBase
    ): array {
        $rankedStrategies = array_keys($walkForwardReport['strategies'] ?? []);
        $rankedStrategies = array_values(array_filter(
            $rankedStrategies,
            fn (string $strategy) => isset($strategyDefinitions[$strategy])
        ));

        $rankedStrategies = array_slice($rankedStrategies, 0, 4);

        for ($i = 0; $i < count($rankedStrategies); $i++) {
            for ($j = $i + 1; $j < count($rankedStrategies); $j++) {
                $strategyA = $rankedStrategies[$i];
                $strategyB = $rankedStrategies[$j];

                $weights = $this->blendWeights(
                    $strategyDefinitions[$strategyA],
                    $strategyDefinitions[$strategyB],
                    0.55
                );

                $base = $this->buildBaseFromProfiles(
                    profiles: $currentProfiles,
                    quantidadeDezenas: $quantidadeDezenas,
                    weights: $weights,
                    forcedNumbers: [],
                    blockedNumbers: [],
                    salt: crc32('hybrid|' . $strategyA . '|' . $strategyB . '|' . $concursoBase->concurso)
                );

                if (count($base) !== $quantidadeDezenas) {
                    continue;
                }

                $metricsA = $walkForwardReport['strategies'][$strategyA] ?? [];
                $metricsB = $walkForwardReport['strategies'][$strategyB] ?? [];
                $hybridMetrics = $this->blendWalkForwardMetrics($metricsA, $metricsB);

                $candidates[] = $this->makeCurrentCandidate(
                    strategy: 'hybrid_' . $strategyA . '_' . $strategyB,
                    numbers: $base,
                    profiles: $currentProfiles,
                    historicalContests: $historicalContests,
                    quantidadeDezenas: $quantidadeDezenas,
                    walkForwardMetrics: $hybridMetrics
                );
            }
        }

        return $candidates;
    }

    protected function addDiversityRescueCandidates(
        array $candidates,
        array $currentProfiles,
        array $historicalContests,
        int $quantidadeDezenas,
        LotofacilConcurso $concursoBase,
        array $patternContext
    ): array {
        $recentDraws = array_slice(array_column($historicalContests, 'numbers'), -80);
        $frequentMissingNumbers = $this->frequentRecentlyMissedNumbers(
            candidates: $candidates,
            historicalDraws: $recentDraws,
            profiles: $currentProfiles
        );

        $topCandidate = $candidates[0] ?? null;

        if (! $topCandidate) {
            return $candidates;
        }

        $base = $this->normalizeNumbers($topCandidate['numbers'] ?? []);

        foreach ([2, 3, 4, 5, 6] as $mutationLevel) {
            $rescued = $this->injectRescueNumbers(
                base: $base,
                profiles: $currentProfiles,
                rescueNumbers: $frequentMissingNumbers,
                quantidadeDezenas: $quantidadeDezenas,
                mutationLevel: $mutationLevel,
                salt: crc32('rescue|' . $mutationLevel . '|' . $concursoBase->concurso)
            );

            if (count($rescued) !== $quantidadeDezenas) {
                continue;
            }

            $candidates[] = $this->makeCurrentCandidate(
                strategy: 'walk_forward_rescue_' . $mutationLevel,
                numbers: $rescued,
                profiles: $currentProfiles,
                historicalContests: $historicalContests,
                quantidadeDezenas: $quantidadeDezenas,
                walkForwardMetrics: $topCandidate['walk_forward'] ?? []
            );
        }

        $candidates = $this->addRepeatSurvivalCandidates(
            candidates: $candidates,
            currentProfiles: $currentProfiles,
            historicalContests: $historicalContests,
            quantidadeDezenas: $quantidadeDezenas,
            concursoBase: $concursoBase,
            walkForwardMetrics: $topCandidate['walk_forward'] ?? []
        );

        return $candidates;
    }

    protected function addRepeatSurvivalCandidates(
        array $candidates,
        array $currentProfiles,
        array $historicalContests,
        int $quantidadeDezenas,
        LotofacilConcurso $concursoBase,
        array $walkForwardMetrics
    ): array {
        $lastDraw = $this->extractNumbers($concursoBase);
        $outsideLastDraw = array_values(array_diff(range(1, 25), $lastDraw));

        if (count($lastDraw) !== 15 || count($outsideLastDraw) !== 10) {
            return $candidates;
        }

        $outsideRankings = [
            'score' => $this->rankNumbersByProfile($outsideLastDraw, $currentProfiles, 'score'),
            'return' => $this->rankNumbersByProfile($outsideLastDraw, $currentProfiles, 'return_pressure'),
            'frequency' => $this->rankNumbersByProfile($outsideLastDraw, $currentProfiles, 'frequency'),
        ];

        $repeatHighScore = $this->rankNumbersByProfile($lastDraw, $currentProfiles, 'score');
        $repeatLowScore = $this->rankNumbersByProfile($lastDraw, $currentProfiles, 'score', true);
        $repeatHighFrequency = $this->rankNumbersByProfile($lastDraw, $currentProfiles, 'frequency');
        $repeatLowStability = $this->rankNumbersByProfile($lastDraw, $currentProfiles, 'stability', true);

        $repeatRankings = [
            'score_barbell' => [$repeatHighScore, $repeatLowScore],
            'frequency_barbell' => [$repeatHighFrequency, $repeatLowScore],
            'stability_barbell' => [$repeatHighScore, $repeatLowStability],
        ];

        $repeatTargets = array_values(array_filter(
            range(max(6, $quantidadeDezenas - 11), min(12, $quantidadeDezenas - 7)),
            fn (int $target): bool => $target > 0 && $target < $quantidadeDezenas
        ));

        foreach ($repeatRankings as $repeatStrategy => [$highRanking, $lowRanking]) {
            foreach ($repeatTargets as $repeatTarget) {
                $highMin = max(2, (int) floor($repeatTarget * 0.25));
                $highMax = min($repeatTarget - 2, (int) ceil($repeatTarget * 0.55));

                for ($highCount = $highMin; $highCount <= $highMax; $highCount++) {
                    $repeatNumbers = $this->barbellRepeatNumbers(
                        highRanking: $highRanking,
                        lowRanking: $lowRanking,
                        repeatTarget: $repeatTarget,
                        highCount: $highCount
                    );

                    if (count($repeatNumbers) !== $repeatTarget) {
                        continue;
                    }

                    foreach ($outsideRankings as $outsideStrategy => $outsideRanking) {
                        $numbers = array_merge(
                            $repeatNumbers,
                            array_slice($outsideRanking, 0, $quantidadeDezenas - $repeatTarget)
                        );

                        $numbers = $this->normalizeNumbers($numbers);

                        if (count($numbers) !== $quantidadeDezenas) {
                            continue;
                        }

                        $candidates[] = $this->makeCurrentCandidate(
                            strategy: 'repeat_survival_' . $repeatStrategy . '_' . $outsideStrategy . '_' . $repeatTarget . '_' . $highCount,
                            numbers: $numbers,
                            profiles: $currentProfiles,
                            historicalContests: $historicalContests,
                            quantidadeDezenas: $quantidadeDezenas,
                            walkForwardMetrics: $walkForwardMetrics
                        );
                    }
                }
            }
        }

        return $candidates;
    }

    protected function addLearningSnapshotCandidates(
        array $candidates,
        array $currentProfiles,
        array $historicalContests,
        int $quantidadeDezenas,
        LotofacilConcurso $concursoBase,
        ?LottusLearningSnapshot $learningSnapshot
    ): array {
        if (
            ! $learningSnapshot
            || ! (bool) config('lottus_fechamento.learning_snapshots.enabled', true)
            || ! $this->shouldGenerateLearningCandidates($learningSnapshot, $quantidadeDezenas)
        ) {
            return $candidates;
        }

        $numberBias = $this->numberBiasFromPairBias($learningSnapshot->pair_bias ?? []);

        if (empty($numberBias)) {
            return $candidates;
        }

        $rankedPairNumbers = array_keys($numberBias);

        usort($rankedPairNumbers, function (int $a, int $b) use ($numberBias): int {
            if (($numberBias[$a] ?? 0.0) === ($numberBias[$b] ?? 0.0)) {
                return $a <=> $b;
            }

            return ($numberBias[$b] ?? 0.0) <=> ($numberBias[$a] ?? 0.0);
        });

        $lastDraw = $this->extractNumbers($concursoBase);
        $learnedWeights = [
            'frequency' => 0.12,
            'delay' => 0.10,
            'cycle' => 0.12,
            'correlation' => 0.38,
            'recent_presence' => 0.04,
            'return_pressure' => 0.14,
            'stability' => 0.10,
            'pair_bias' => 0.20,
        ];

        $profilesWithBias = $currentProfiles;

        foreach ($profilesWithBias as $number => &$profile) {
            $profile['pair_bias'] = (float) ($numberBias[(int) $number] ?? 0.0);
        }

        unset($profile);

        $forcedSets = [
            'pair_core' => array_slice($rankedPairNumbers, 0, min(12, $quantidadeDezenas)),
            'pair_repeat_core' => array_values(array_unique(array_merge(
                array_slice(array_values(array_intersect($rankedPairNumbers, $lastDraw)), 0, 8),
                array_slice($rankedPairNumbers, 0, 8)
            ))),
            'pair_wide_core' => array_slice($rankedPairNumbers, 0, min(15, $quantidadeDezenas)),
        ];

        foreach ($forcedSets as $strategy => $forcedNumbers) {
            $base = $this->buildBaseFromProfiles(
                profiles: $profilesWithBias,
                quantidadeDezenas: $quantidadeDezenas,
                weights: $this->normalizeGenericWeights($learnedWeights),
                forcedNumbers: $forcedNumbers,
                blockedNumbers: [],
                salt: crc32('learning_snapshot|' . $strategy . '|' . $concursoBase->concurso)
            );

            if (count($base) !== $quantidadeDezenas) {
                continue;
            }

            $candidate = $this->makeCurrentCandidate(
                strategy: 'learning_snapshot_' . $strategy,
                numbers: $base,
                profiles: $profilesWithBias,
                historicalContests: $historicalContests,
                quantidadeDezenas: $quantidadeDezenas,
                walkForwardMetrics: []
            );

            $candidate['fitness'] = round(((float) ($candidate['fitness'] ?? 0.0)) - 120.0, 8);
            $candidates[] = $candidate;
        }

        return $candidates;
    }

    protected function barbellRepeatNumbers(
        array $highRanking,
        array $lowRanking,
        int $repeatTarget,
        int $highCount
    ): array {
        $numbers = [];

        foreach (array_slice($highRanking, 0, $highCount) as $number) {
            if (! in_array($number, $numbers, true)) {
                $numbers[] = $number;
            }
        }

        foreach ($lowRanking as $number) {
            if (count($numbers) >= $repeatTarget) {
                break;
            }

            if (! in_array($number, $numbers, true)) {
                $numbers[] = $number;
            }
        }

        return $this->normalizeNumbers(array_slice($numbers, 0, $repeatTarget));
    }

    protected function rankNumbersByProfile(
        array $numbers,
        array $profiles,
        string $metric,
        bool $ascending = false
    ): array {
        $numbers = $this->normalizeNumbers($numbers);

        usort($numbers, function (int $a, int $b) use ($profiles, $metric, $ascending): int {
            $aValue = (float) ($profiles[$a][$metric] ?? 0.0);
            $bValue = (float) ($profiles[$b][$metric] ?? 0.0);

            if ($aValue === $bValue) {
                return $a <=> $b;
            }

            return $ascending ? ($aValue <=> $bValue) : ($bValue <=> $aValue);
        });

        return $numbers;
    }

    protected function buildCurrentNumberProfiles(
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
            $frequency = (float) ($frequencyScores[$number] ?? 0.5);
            $delay = (float) ($delayScores[$number] ?? 0.5);
            $cycle = (float) ($cycleScores[$number] ?? 0.5);
            $correlation = $this->averageCorrelation($number, $pairScores);
            $recentPresence = in_array($number, $lastDraw, true) ? 1.0 : 0.0;
            $cycleMissingPresence = in_array($number, $faltantes, true) ? 1.0 : 0.0;
            $returnPressure = max(0.0, min(1.0, ($delay * 0.48) + ($cycle * 0.34) + ($cycleMissingPresence * 0.18)));
            $stability = $this->stabilityScore($frequency, $delay, $cycle);
            $score = max(0.0, min(1.0,
                ($frequency * 0.18) +
                ($delay * 0.18) +
                ($cycle * 0.20) +
                ($correlation * 0.20) +
                ($recentPresence * 0.04) +
                ($returnPressure * 0.16) +
                ($stability * 0.04)
            ));

            $profiles[$number] = [
                'number' => $number,
                'score' => round($score, 8),
                'frequency' => $frequency,
                'delay' => $delay,
                'cycle' => $cycle,
                'correlation' => $correlation,
                'recent_presence' => $recentPresence,
                'return_pressure' => $returnPressure,
                'stability' => $stability,
                'temperature' => $this->temperature($frequency, $delay, $cycle),
                'line' => $this->line($number),
                'zone' => $this->zone($number),
            ];
        }

        return $profiles;
    }

    protected function buildProfilesFromContests(array $trainingContests): array
    {
        $draws = array_values(array_filter(
            array_column($trainingContests, 'numbers'),
            fn ($numbers) => is_array($numbers) && count($numbers) === 15
        ));

        $window40 = array_slice($draws, -40);
        $window80 = array_slice($draws, -80);
        $window160 = array_slice($draws, -160);
        $lastDraw = end($draws) ?: [];

        $frequency40 = $this->frequencyMap($window40);
        $frequency80 = $this->frequencyMap($window80);
        $frequency160 = $this->frequencyMap($window160);
        $frequencyTotal = $this->frequencyMap($draws);
        $delayMap = $this->delayMap($draws);
        $cycleMap = $this->cycleMap($draws);
        $correlationMap = $this->correlationMap($window80);

        $profiles = [];

        foreach (range(1, 25) as $number) {
            $frequency =
                (($frequency40[$number] ?? 0.0) * 0.20) +
                (($frequency80[$number] ?? 0.0) * 0.26) +
                (($frequency160[$number] ?? 0.0) * 0.30) +
                (($frequencyTotal[$number] ?? 0.0) * 0.24);

            $delay = (float) ($delayMap[$number] ?? 0.5);
            $cycle = (float) ($cycleMap[$number] ?? 0.5);
            $correlation = (float) ($correlationMap[$number] ?? 0.5);
            $recentPresence = in_array($number, $lastDraw, true) ? 1.0 : 0.0;
            $returnPressure = max(0.0, min(1.0, ($delay * 0.54) + ($cycle * 0.36) + ((1.0 - $frequency) * 0.10)));
            $stability = $this->stabilityScore($frequency, $delay, $cycle);
            $score = max(0.0, min(1.0,
                ($frequency * 0.18) +
                ($delay * 0.18) +
                ($cycle * 0.20) +
                ($correlation * 0.20) +
                ($recentPresence * 0.04) +
                ($returnPressure * 0.16) +
                ($stability * 0.04)
            ));

            $profiles[$number] = [
                'number' => $number,
                'score' => round($score, 8),
                'frequency' => round($frequency, 8),
                'delay' => round($delay, 8),
                'cycle' => round($cycle, 8),
                'correlation' => round($correlation, 8),
                'recent_presence' => $recentPresence,
                'return_pressure' => round($returnPressure, 8),
                'stability' => round($stability, 8),
                'temperature' => $this->temperature($frequency, $delay, $cycle),
                'line' => $this->line($number),
                'zone' => $this->zone($number),
            ];
        }

        return $profiles;
    }

    protected function buildBaseFromProfiles(
        array $profiles,
        int $quantidadeDezenas,
        array $weights,
        array $forcedNumbers = [],
        array $blockedNumbers = [],
        int $salt = 0
    ): array {
        $weights = $this->normalizeGenericWeights($weights);
        $forcedNumbers = $this->normalizeNumbers($forcedNumbers);
        $blockedNumbers = $this->normalizeNumbers($blockedNumbers);
        $selected = [];

        foreach ($forcedNumbers as $number) {
            if (count($selected) >= $quantidadeDezenas) {
                break;
            }

            if (isset($profiles[$number]) && ! in_array($number, $blockedNumbers, true)) {
                $selected[] = $number;
            }
        }

        $ranked = array_values($profiles);

        foreach ($ranked as &$profile) {
            $number = (int) ($profile['number'] ?? 0);
            $score = 0.0;

            foreach ($weights as $metric => $weight) {
                $score += (float) ($profile[$metric] ?? 0.0) * (float) $weight;
            }

            $score += $this->deterministicJitter($number, $salt);
            $profile['_selection_score'] = round($score, 8);
        }

        unset($profile);

        usort($ranked, function (array $a, array $b): int {
            if (($a['_selection_score'] ?? 0.0) === ($b['_selection_score'] ?? 0.0)) {
                return ((int) ($a['number'] ?? 0)) <=> ((int) ($b['number'] ?? 0));
            }

            return ($b['_selection_score'] ?? 0.0) <=> ($a['_selection_score'] ?? 0.0);
        });

        $quotas = $this->temperatureQuotas($quantidadeDezenas);

        foreach ($quotas as $temperature => $quota) {
            $bucket = array_values(array_filter(
                $ranked,
                fn (array $profile) => ($profile['temperature'] ?? 'neutral') === $temperature
            ));

            $this->fillSelected(
                selected: $selected,
                ranked: $bucket,
                profiles: $profiles,
                quantidadeDezenas: $quantidadeDezenas,
                needed: $quota,
                blockedNumbers: $blockedNumbers,
                relaxed: false
            );
        }

        $this->fillSelected(
            selected: $selected,
            ranked: $ranked,
            profiles: $profiles,
            quantidadeDezenas: $quantidadeDezenas,
            needed: $quantidadeDezenas - count($selected),
            blockedNumbers: $blockedNumbers,
            relaxed: true
        );

        $selected = array_slice($this->normalizeNumbers($selected), 0, $quantidadeDezenas);
        sort($selected);

        return $selected;
    }

    protected function fillSelected(
        array &$selected,
        array $ranked,
        array $profiles,
        int $quantidadeDezenas,
        int $needed,
        array $blockedNumbers = [],
        bool $relaxed = false
    ): void {
        if ($needed <= 0) {
            return;
        }

        foreach ($ranked as $profile) {
            if (count($selected) >= $quantidadeDezenas || $needed <= 0) {
                break;
            }

            $number = (int) ($profile['number'] ?? 0);

            if ($number < 1 || $number > 25) {
                continue;
            }

            if (in_array($number, $selected, true) || in_array($number, $blockedNumbers, true)) {
                continue;
            }

            $candidate = $selected;
            $candidate[] = $number;
            $candidate = $this->normalizeNumbers($candidate);

            if (! $relaxed && ! $this->isStructurallyAcceptable($candidate, $profiles, $quantidadeDezenas)) {
                continue;
            }

            $selected[] = $number;
            $selected = $this->normalizeNumbers($selected);
            $needed--;
        }
    }

    protected function attachSimulationMetricsToCandidates(
        array $candidates,
        array $historicalContests,
        int $quantidadeDezenas
    ): array {
        foreach ($candidates as &$candidate) {
            $simulation = $this->baseSimulationMetrics(
                base: $candidate['numbers'] ?? [],
                historicalContests: $historicalContests,
                quantidadeDezenas: $quantidadeDezenas
            );

            $originalFitness = (float) ($candidate['fitness'] ?? 0.0);
            $simulationScore = (float) ($simulation['score'] ?? 0.0);

            $candidate['simulation'] = $simulation;
            $candidate['simulation_score'] = round($simulationScore, 8);
            $candidate['fitness'] = round(($simulationScore * 1.0) + ($originalFitness * 0.28), 8);
        }

        unset($candidate);

        return $candidates;
    }

    protected function baseSimulationMetrics(
        array $base,
        array $historicalContests,
        int $quantidadeDezenas
    ): array {
        $base = $this->normalizeNumbers($base);
        $draws = array_slice(array_column($historicalContests, 'numbers'), -260);

        if (empty($base) || count($base) !== $quantidadeDezenas || empty($draws)) {
            return [
                'score' => 0.0,
                'samples' => 0,
                'avg_hits' => 0.0,
                'p90_hits' => 0.0,
                'min_hits' => 0,
                'max_hits' => 0,
                'coverage_11' => 0.0,
                'coverage_12' => 0.0,
                'coverage_13' => 0.0,
                'coverage_14' => 0.0,
                'coverage_15' => 0.0,
                'recent_peak_score' => 0.0,
                'hit_distribution' => [],
            ];
        }

        $total = 0;
        $min = 15;
        $max = 0;
        $coverage11 = 0;
        $coverage12 = 0;
        $coverage13 = 0;
        $coverage14 = 0;
        $coverage15 = 0;
        $recentPeakScore = 0.0;
        $hitDistribution = [];
        $sampleCount = 0;

        foreach ($draws as $index => $draw) {
            if (! is_array($draw) || count($draw) !== 15) {
                continue;
            }

            $draw = $this->normalizeNumbers($draw);
            $hits = count(array_intersect($base, $draw));
            $recentWeight = $this->simulationRecentWeight($index, count($draws));

            $sampleCount++;
            $total += $hits;
            $min = min($min, $hits);
            $max = max($max, $hits);
            $hitDistribution[] = $hits;

            if ($hits >= 11) {
                $coverage11++;
            }

            if ($hits >= 12) {
                $coverage12++;
            }

            if ($hits >= 13) {
                $coverage13++;
            }

            if ($hits >= 14) {
                $coverage14++;
            }

            if ($hits >= 15) {
                $coverage15++;
            }

            if ($hits >= 15) {
                $recentPeakScore += 38.0 * $recentWeight;
            } elseif ($hits >= 14) {
                $recentPeakScore += 18.0 * $recentWeight;
            } elseif ($hits >= 13) {
                $recentPeakScore += 5.5 * $recentWeight;
            } elseif ($hits >= 12) {
                $recentPeakScore += 1.0 * $recentWeight;
            } elseif ($hits <= 9) {
                $recentPeakScore -= 0.9 * $recentWeight;
            }
        }

        if ($sampleCount === 0) {
            return [
                'score' => 0.0,
                'samples' => 0,
                'avg_hits' => 0.0,
                'p90_hits' => 0.0,
                'min_hits' => 0,
                'max_hits' => 0,
                'coverage_11' => 0.0,
                'coverage_12' => 0.0,
                'coverage_13' => 0.0,
                'coverage_14' => 0.0,
                'coverage_15' => 0.0,
                'recent_peak_score' => 0.0,
                'hit_distribution' => [],
            ];
        }

        sort($hitDistribution);

        $p90Index = min(count($hitDistribution) - 1, max(0, (int) floor(count($hitDistribution) * 0.90)));
        $p90Hits = (int) ($hitDistribution[$p90Index] ?? 0);
        $avgHits = $total / $sampleCount;
        $coverage11Rate = $coverage11 / $sampleCount;
        $coverage12Rate = $coverage12 / $sampleCount;
        $coverage13Rate = $coverage13 / $sampleCount;
        $coverage14Rate = $coverage14 / $sampleCount;
        $coverage15Rate = $coverage15 / $sampleCount;
        $peakDensity = $this->simulationPeakDensity($hitDistribution);

        $baseScore =
            ($max * 95.0) +
            ($p90Hits * 28.0) +
            ($peakDensity * 180.0) +
            ($coverage15Rate * 2400.0) +
            ($coverage14Rate * 1250.0) +
            ($coverage13Rate * 260.0) +
            ($coverage12Rate * 24.0) +
            ($coverage11Rate * 6.0) +
            ($recentPeakScore * 24.0) +
            ($avgHits * 1.4);

        if ($coverage14Rate <= 0.0 && $max < 14) {
            $baseScore -= 240.0;
        }

        $score = $baseScore;

        if ($max >= 15) {
            $score =
                200000.0 +
                ($coverage15Rate * 60000.0) +
                ($coverage14Rate * 24000.0) +
                ($coverage13Rate * 1800.0) +
                ($recentPeakScore * 120.0) +
                ($peakDensity * 900.0) +
                ($baseScore * 0.12);
        } elseif ($max >= 14) {
            $score =
                100000.0 +
                ($coverage14Rate * 42000.0) +
                ($coverage13Rate * 2200.0) +
                ($recentPeakScore * 95.0) +
                ($peakDensity * 720.0) +
                ($baseScore * 0.14);
        } elseif ($max >= 13) {
            $score =
                10000.0 +
                ($coverage13Rate * 2500.0) +
                ($recentPeakScore * 32.0) +
                ($peakDensity * 260.0) +
                ($baseScore * 0.25);
        }

        return [
            'score' => round($score, 8),
            'samples' => $sampleCount,
            'avg_hits' => round($avgHits, 8),
            'p90_hits' => $p90Hits,
            'min_hits' => $min,
            'max_hits' => $max,
            'coverage_11' => round($coverage11Rate, 8),
            'coverage_12' => round($coverage12Rate, 8),
            'coverage_13' => round($coverage13Rate, 8),
            'coverage_14' => round($coverage14Rate, 8),
            'coverage_15' => round($coverage15Rate, 8),
            'recent_peak_score' => round($recentPeakScore, 8),
            'peak_density' => round($peakDensity, 8),
            'hit_distribution' => $hitDistribution,
        ];
    }

    protected function simulationRecentWeight(int $index, int $total): float
    {
        if ($total <= 1) {
            return 1.0;
        }

        $position = $index / max(1, $total - 1);

        return 0.35 + ($position * 1.65);
    }

    protected function simulationPeakDensity(array $hitDistribution): float
    {
        if (empty($hitDistribution)) {
            return 0.0;
        }

        $score = 0.0;

        foreach ($hitDistribution as $hits) {
            $hits = (int) $hits;

            if ($hits >= 15) {
                $score += 18.0;
            } elseif ($hits >= 14) {
                $score += 8.0;
            } elseif ($hits >= 13) {
                $score += 2.2;
            } elseif ($hits >= 12) {
                $score += 0.35;
            } elseif ($hits <= 9) {
                $score -= 0.25;
            }
        }

        return $score / max(1, count($hitDistribution));
    }

    protected function baseHistoricalRobustness(array $base, array $historicalContests): array
    {
        $draws = array_slice(array_column($historicalContests, 'numbers'), -160);

        if (empty($draws)) {
            return [
                'avg_hits' => 0.0,
                'coverage_11' => 0.0,
                'coverage_12' => 0.0,
                'coverage_13' => 0.0,
                'coverage_14' => 0.0,
                'min_hits' => 0,
                'max_hits' => 0,
            ];
        }

        $total = 0;
        $coverage11 = 0;
        $coverage12 = 0;
        $coverage13 = 0;
        $coverage14 = 0;
        $min = 15;
        $max = 0;

        foreach ($draws as $draw) {
            $hits = count(array_intersect($base, $draw));
            $total += $hits;
            $min = min($min, $hits);
            $max = max($max, $hits);

            if ($hits >= 11) {
                $coverage11++;
            }

            if ($hits >= 12) {
                $coverage12++;
            }

            if ($hits >= 13) {
                $coverage13++;
            }

            if ($hits >= 14) {
                $coverage14++;
            }
        }

        $count = max(1, count($draws));

        return [
            'avg_hits' => round($total / $count, 8),
            'coverage_11' => round($coverage11 / $count, 8),
            'coverage_12' => round($coverage12 / $count, 8),
            'coverage_13' => round($coverage13 / $count, 8),
            'coverage_14' => round($coverage14 / $count, 8),
            'min_hits' => $min,
            'max_hits' => $max,
        ];
    }

    protected function baseProfile(array $numbers, array $profiles): array
    {
        $numbers = $this->normalizeNumbers($numbers);

        if (empty($numbers)) {
            return [
                'avg_score' => 0.0,
                'entropy' => 0.0,
                'line_balance' => 0.0,
                'zone_balance' => 0.0,
                'concentration_penalty' => 1.0,
            ];
        }

        $scores = [];
        $lines = [];
        $zones = [];
        $temperatures = [];

        foreach ($numbers as $number) {
            $profile = $profiles[$number] ?? [];

            $scores[] = (float) ($profile['score'] ?? 0.0);

            $line = (int) ($profile['line'] ?? $this->line($number));
            $zone = (string) ($profile['zone'] ?? $this->zone($number));
            $temperature = (string) ($profile['temperature'] ?? 'neutral');

            $lines[$line] = ($lines[$line] ?? 0) + 1;
            $zones[$zone] = ($zones[$zone] ?? 0) + 1;
            $temperatures[$temperature] = ($temperatures[$temperature] ?? 0) + 1;
        }

        $lineBalance = $this->balanceScore($lines, count($numbers), 5);
        $zoneBalance = $this->balanceScore($zones, count($numbers), 5);
        $entropy = max(0.0, min(1.0,
            ($this->entropyScore($temperatures, count($numbers)) * 0.34) +
            ($this->entropyScore($lines, count($numbers)) * 0.33) +
            ($this->entropyScore($zones, count($numbers)) * 0.33)
        ));

        $concentrationPenalty = max(0.0, min(1.0,
            ((max($lines ?: [0]) / max(1, count($numbers))) * 0.55) +
            ((max($zones ?: [0]) / max(1, count($numbers))) * 0.45)
        ));

        return [
            'avg_score' => round(array_sum($scores) / max(1, count($scores)), 8),
            'entropy' => round($entropy, 8),
            'line_balance' => round($lineBalance, 8),
            'zone_balance' => round($zoneBalance, 8),
            'concentration_penalty' => round($concentrationPenalty, 8),
            'line_distribution' => $lines,
            'zone_distribution' => $zones,
            'temperature_distribution' => $temperatures,
        ];
    }

    protected function isBaseCommerciallyFragile(array $numbers, array $profiles, int $quantidadeDezenas): bool
    {
        $profile = $this->baseProfile($numbers, $profiles);

        if (($profile['entropy'] ?? 0.0) < 0.70) {
            return true;
        }

        if (($profile['line_balance'] ?? 0.0) < 0.58) {
            return true;
        }

        if (($profile['zone_balance'] ?? 0.0) < 0.58) {
            return true;
        }

        return false;
    }

    protected function isStructurallyAcceptable(array $numbers, array $profiles, int $quantidadeDezenas): bool
    {
        $numbers = $this->normalizeNumbers($numbers);

        if (empty($numbers)) {
            return true;
        }

        $lines = [];
        $zones = [];
        $temperatures = [];

        foreach ($numbers as $number) {
            $line = (int) ($profiles[$number]['line'] ?? $this->line($number));
            $zone = (string) ($profiles[$number]['zone'] ?? $this->zone($number));
            $temperature = (string) ($profiles[$number]['temperature'] ?? 'neutral');

            $lines[$line] = ($lines[$line] ?? 0) + 1;
            $zones[$zone] = ($zones[$zone] ?? 0) + 1;
            $temperatures[$temperature] = ($temperatures[$temperature] ?? 0) + 1;
        }

        $maxLine = max($lines ?: [0]);
        $maxZone = max($zones ?: [0]);
        $hotCount = (int) ($temperatures['hot'] ?? 0);
        $coldCount = (int) ($temperatures['cold'] ?? 0);

        $maxLineAllowed = $quantidadeDezenas >= 19 ? 6 : 5;
        $maxZoneAllowed = $quantidadeDezenas >= 19 ? 6 : 5;

        if ($maxLine > $maxLineAllowed) {
            return false;
        }

        if ($maxZone > $maxZoneAllowed) {
            return false;
        }

        if ($hotCount > (int) ceil($quantidadeDezenas * 0.62)) {
            return false;
        }

        if ($coldCount > (int) ceil($quantidadeDezenas * 0.46)) {
            return false;
        }

        return true;
    }

    protected function frequentRecentlyMissedNumbers(array $candidates, array $historicalDraws, array $profiles): array
    {
        $topBases = array_slice($candidates, 0, 5);
        $missed = [];

        foreach ($topBases as $candidate) {
            $base = $this->normalizeNumbers($candidate['numbers'] ?? []);

            foreach ($historicalDraws as $draw) {
                $draw = $this->normalizeNumbers($draw);
                $missing = array_values(array_diff($draw, $base));

                foreach ($missing as $number) {
                    $missed[$number] = ($missed[$number] ?? 0) + 1;
                }
            }
        }

        arsort($missed);

        $numbers = array_keys($missed);

        usort($numbers, function (int $a, int $b) use ($missed, $profiles): int {
            if (($missed[$a] ?? 0) === ($missed[$b] ?? 0)) {
                return ((float) ($profiles[$b]['score'] ?? 0.0)) <=> ((float) ($profiles[$a]['score'] ?? 0.0));
            }

            return ($missed[$b] ?? 0) <=> ($missed[$a] ?? 0);
        });

        return array_slice($numbers, 0, 8);
    }

    protected function injectRescueNumbers(
        array $base,
        array $profiles,
        array $rescueNumbers,
        int $quantidadeDezenas,
        int $mutationLevel,
        int $salt
    ): array {
        $base = $this->normalizeNumbers($base);
        $rescueNumbers = $this->normalizeNumbers($rescueNumbers);

        $inside = $base;

        usort($inside, function (int $a, int $b) use ($profiles): int {
            return ((float) ($profiles[$a]['score'] ?? 0.0)) <=> ((float) ($profiles[$b]['score'] ?? 0.0));
        });

        $outsideRescue = array_values(array_diff($rescueNumbers, $base));

        if (empty($outsideRescue)) {
            return $base;
        }

        $changes = min($mutationLevel, count($outsideRescue), count($inside));
        $candidate = $base;

        for ($i = 0; $i < $changes; $i++) {
            $remove = $inside[$i] ?? null;
            $add = $outsideRescue[$i] ?? null;

            if (! $remove || ! $add) {
                continue;
            }

            $candidate = array_values(array_diff($candidate, [$remove]));
            $candidate[] = $add;
            $candidate = $this->normalizeNumbers($candidate);
        }

        if (count($candidate) !== $quantidadeDezenas) {
            return $base;
        }

        return $candidate;
    }

    protected function frequencyMap(array $draws): array
    {
        $count = max(1, count($draws));
        $frequency = array_fill_keys(range(1, 25), 0.0);

        foreach ($draws as $draw) {
            foreach ($draw as $number) {
                $frequency[(int) $number] = ($frequency[(int) $number] ?? 0.0) + 1;
            }
        }

        foreach ($frequency as $number => $value) {
            $frequency[$number] = $value / $count;
        }

        return $this->normalizeScores($frequency);
    }

    protected function delayMap(array $draws): array
    {
        $delay = array_fill_keys(range(1, 25), count($draws));

        foreach (array_reverse($draws) as $distance => $draw) {
            foreach ($draw as $number) {
                if ($delay[(int) $number] === count($draws)) {
                    $delay[(int) $number] = $distance;
                }
            }
        }

        return $this->normalizeScores($delay);
    }

    protected function cycleMap(array $draws): array
    {
        $window = array_slice($draws, -8);
        $seen = [];

        foreach ($window as $draw) {
            foreach ($draw as $number) {
                $seen[(int) $number] = ($seen[(int) $number] ?? 0) + 1;
            }
        }

        $cycle = [];

        foreach (range(1, 25) as $number) {
            $cycle[$number] = 1.0 - min(1.0, (($seen[$number] ?? 0) / max(1, count($window))));
        }

        return $cycle;
    }

    protected function correlationMap(array $draws): array
    {
        $pairFrequency = [];
        $numberScore = array_fill_keys(range(1, 25), 0.0);

        foreach ($draws as $draw) {
            $draw = $this->normalizeNumbers($draw);

            for ($i = 0; $i < count($draw); $i++) {
                for ($j = $i + 1; $j < count($draw); $j++) {
                    $key = $draw[$i] . '-' . $draw[$j];
                    $pairFrequency[$key] = ($pairFrequency[$key] ?? 0) + 1;
                }
            }
        }

        foreach ($pairFrequency as $key => $value) {
            [$a, $b] = array_map('intval', explode('-', $key));
            $numberScore[$a] += $value;
            $numberScore[$b] += $value;
        }

        return $this->normalizeScores($numberScore);
    }

    protected function applyPatternBias(array $weights, array $patternContext): array
    {
        $regime = $patternContext['regime'] ?? null;
        $confidence = (float) ($patternContext['confidence'] ?? 0.0);

        if ($confidence < 0.45) {
            return $this->normalizeGenericWeights($weights);
        }

        if ($regime === 'volatile') {
            $weights['delay'] = ($weights['delay'] ?? 0.0) + 0.04;
            $weights['cycle'] = ($weights['cycle'] ?? 0.0) + 0.04;
            $weights['recent_presence'] = max(0.0, ($weights['recent_presence'] ?? 0.0) - 0.04);
        }

        if ($regime === 'stable') {
            $weights['correlation'] = ($weights['correlation'] ?? 0.0) + 0.04;
            $weights['recent_presence'] = ($weights['recent_presence'] ?? 0.0) + 0.04;
            $weights['delay'] = max(0.0, ($weights['delay'] ?? 0.0) - 0.03);
        }

        return $this->normalizeGenericWeights($weights);
    }

    protected function blendWeights(array $a, array $b, float $aWeight): array
    {
        $keys = array_values(array_unique(array_merge(array_keys($a), array_keys($b))));
        $weights = [];

        foreach ($keys as $key) {
            $weights[$key] = ((float) ($a[$key] ?? 0.0) * $aWeight) + ((float) ($b[$key] ?? 0.0) * (1.0 - $aWeight));
        }

        return $this->normalizeGenericWeights($weights);
    }

    protected function blendWalkForwardMetrics(array $a, array $b): array
    {
        $keys = array_values(array_unique(array_merge(array_keys($a), array_keys($b))));
        $result = [];

        foreach ($keys as $key) {
            if (is_numeric($a[$key] ?? null) || is_numeric($b[$key] ?? null)) {
                $result[$key] = (((float) ($a[$key] ?? 0.0)) + ((float) ($b[$key] ?? 0.0))) / 2;
            }
        }

        return $result;
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

    protected function uniqueCandidates(array $candidates): array
    {
        $unique = [];

        foreach ($candidates as $candidate) {
            $numbers = $this->normalizeNumbers($candidate['numbers'] ?? []);
            $key = $this->key($numbers);

            if ($key === '') {
                continue;
            }

            if (! isset($unique[$key]) || (($candidate['fitness'] ?? 0.0) > ($unique[$key]['fitness'] ?? 0.0))) {
                $candidate['numbers'] = $numbers;
                $candidate['key'] = $key;
                $unique[$key] = $candidate;
            }
        }

        return array_values($unique);
    }

    protected function walkForwardStability(array $hits): float
    {
        if (empty($hits)) {
            return 0.0;
        }

        $avg = array_sum($hits) / count($hits);
        $variance = 0.0;

        foreach ($hits as $hit) {
            $variance += (($hit - $avg) ** 2);
        }

        $variance = $variance / max(1, count($hits));
        $std = sqrt($variance);

        return max(0.0, min(1.0, 1.0 - ($std / 5.0)));
    }

    protected function recentEvaluationWeight(int $index, int $total): float
    {
        $position = $index / max(1, $total);

        return 0.65 + ($position * 0.35);
    }

    protected function temperatureQuotas(int $quantidadeDezenas): array
    {
        return match ($quantidadeDezenas) {
            16 => ['hot' => 6, 'neutral' => 6, 'cold' => 4],
            17 => ['hot' => 7, 'neutral' => 6, 'cold' => 4],
            18 => ['hot' => 7, 'neutral' => 7, 'cold' => 4],
            19 => ['hot' => 8, 'neutral' => 7, 'cold' => 4],
            20 => ['hot' => 8, 'neutral' => 8, 'cold' => 4],
            default => ['hot' => 7, 'neutral' => 7, 'cold' => 4],
        };
    }

    protected function stabilityScore(float $frequency, float $delay, float $cycle): float
    {
        $centerDistance =
            abs($frequency - 0.55) +
            abs($delay - 0.45) +
            abs($cycle - 0.50);

        return max(0.0, min(1.0, 1.0 - ($centerDistance / 3.0)));
    }

    protected function temperature(float $frequency, float $delay, float $cycle): string
    {
        $heat = ($frequency * 0.46) + ((1.0 - $delay) * 0.24) + ($cycle * 0.30);

        if ($heat >= 0.62) {
            return 'hot';
        }

        if ($heat <= 0.38) {
            return 'cold';
        }

        return 'neutral';
    }

    protected function averageCorrelation(int $number, array $pairScores): float
    {
        $values = [];

        foreach ($pairScores as $key => $score) {
            $parts = array_map('intval', explode('-', (string) $key));

            if (count($parts) !== 2) {
                continue;
            }

            if ($parts[0] === $number || $parts[1] === $number) {
                $values[] = (float) $score;
            }
        }

        if (empty($values)) {
            return 0.5;
        }

        return max(0.0, min(1.0, array_sum($values) / count($values)));
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

    protected function normalizeScores(array $scores): array
    {
        $normalized = [];

        foreach ($scores as $key => $value) {
            $normalized[(int) $key] = (float) $value;
        }

        if (empty($normalized)) {
            return array_fill_keys(range(1, 25), 0.5);
        }

        $min = min($normalized);
        $max = max($normalized);

        foreach (range(1, 25) as $number) {
            $value = (float) ($normalized[$number] ?? 0.0);

            if ($max <= $min) {
                $normalized[$number] = 0.5;
            } else {
                $normalized[$number] = ($value - $min) / ($max - $min);
            }
        }

        return $normalized;
    }

    protected function extractHistoricalContests(Collection $historico, LotofacilConcurso $concursoBase): array
    {
        return $historico
            ->filter(function ($concurso) use ($concursoBase) {
                $numero = is_array($concurso)
                    ? (int) ($concurso['concurso'] ?? 0)
                    : (int) ($concurso->concurso ?? 0);

                return $numero <= (int) $concursoBase->concurso;
            })
            ->sortBy(function ($concurso) {
                return is_array($concurso)
                    ? (int) ($concurso['concurso'] ?? 0)
                    : (int) ($concurso->concurso ?? 0);
            })
            ->map(function ($concurso) {
                return [
                    'concurso' => is_array($concurso)
                        ? (int) ($concurso['concurso'] ?? 0)
                        : (int) ($concurso->concurso ?? 0),
                    'numbers' => $this->extractNumbers($concurso),
                ];
            })
            ->filter(fn (array $item) => count($item['numbers'] ?? []) === 15)
            ->values()
            ->all();
    }

    protected function extractNumbers($concurso): array
    {
        if (is_array($concurso)) {
            foreach (['numbers', 'dezenas'] as $key) {
                if (! empty($concurso[$key]) && is_array($concurso[$key])) {
                    return $this->normalizeNumbers($concurso[$key]);
                }
            }
        }

        $numbers = [];

        for ($i = 1; $i <= 15; $i++) {
            $field = 'bola' . $i;

            if (is_array($concurso)) {
                if (isset($concurso[$field])) {
                    $numbers[] = (int) $concurso[$field];
                }

                continue;
            }

            if (isset($concurso->{$field})) {
                $numbers[] = (int) $concurso->{$field};
            }
        }

        return $this->normalizeNumbers($numbers);
    }

    protected function normalizeNumbers(array $numbers): array
    {
        $numbers = array_values(array_unique(array_map('intval', $numbers)));
        $numbers = array_values(array_filter($numbers, fn (int $number) => $number >= 1 && $number <= 25));
        sort($numbers);

        return $numbers;
    }

    protected function key(array $numbers): string
    {
        return implode('-', $this->normalizeNumbers($numbers));
    }

    protected function deterministicJitter(int $number, int $salt): float
    {
        return ((crc32($number . '|' . $salt) % 1000) / 1000) * 0.006;
    }

    protected function line(int $number): int
    {
        return (int) ceil($number / 5);
    }

    protected function zone(int $number): string
    {
        return match (true) {
            $number <= 5 => 'zone_1',
            $number <= 10 => 'zone_2',
            $number <= 15 => 'zone_3',
            $number <= 20 => 'zone_4',
            default => 'zone_5',
        };
    }
}
