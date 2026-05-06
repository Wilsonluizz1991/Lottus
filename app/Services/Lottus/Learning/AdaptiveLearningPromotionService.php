<?php

namespace App\Services\Lottus\Learning;

use App\Models\LotofacilConcurso;
use App\Models\LottusLearningSnapshot;
use App\Services\Lottus\Analysis\CorrelationAnalysisService;
use App\Services\Lottus\Analysis\CycleAnalysisService;
use App\Services\Lottus\Analysis\DelayAnalysisService;
use App\Services\Lottus\Analysis\FrequencyAnalysisService;
use App\Services\Lottus\Analysis\StructureAnalysisService;
use App\Services\Lottus\Data\HistoricalDataService;
use App\Services\Lottus\Fechamento\FechamentoBaseCompetitionService;
use App\Services\Lottus\Fechamento\FechamentoCandidateSelector;
use App\Services\Lottus\Fechamento\FechamentoCombinationGenerator;
use App\Services\Lottus\Fechamento\FechamentoCoverageOptimizerService;
use App\Services\Lottus\Fechamento\FechamentoPatternPredictionService;
use App\Services\Lottus\Fechamento\FechamentoReducer;
use App\Services\Lottus\Fechamento\FechamentoScoreService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class AdaptiveLearningPromotionService
{
    public function __construct(
        protected HistoricalDataService $historicalDataService,
        protected FrequencyAnalysisService $frequencyAnalysisService,
        protected DelayAnalysisService $delayAnalysisService,
        protected CorrelationAnalysisService $correlationAnalysisService,
        protected StructureAnalysisService $structureAnalysisService,
        protected CycleAnalysisService $cycleAnalysisService,
        protected FechamentoPatternPredictionService $patternPredictionService,
        protected FechamentoCandidateSelector $candidateSelector,
        protected FechamentoBaseCompetitionService $baseCompetitionService,
        protected FechamentoCombinationGenerator $combinationGenerator,
        protected FechamentoScoreService $scoreService,
        protected FechamentoCoverageOptimizerService $coverageOptimizerService,
        protected FechamentoReducer $reducer
    ) {
    }

    public function validateSnapshotForTarget(int $targetConcurso, bool $force = false): ?LottusLearningSnapshot
    {
        if (! (bool) config('lottus_fechamento.learning_snapshots.validation.enabled', true)) {
            return null;
        }

        $snapshot = LottusLearningSnapshot::query()
            ->where('target_concurso', $targetConcurso)
            ->orderByDesc('calibration_version')
            ->first();

        if (! $snapshot) {
            return null;
        }

        if ($snapshot->validated_at && ! $force) {
            return $snapshot;
        }

        $startedAt = microtime(true);

        Log::info('LOTTUS_LEARNING_SHADOW_VALIDATION_STARTED', [
            'snapshot_id' => $snapshot->id,
            'concurso' => $snapshot->concurso,
            'target_concurso' => $snapshot->target_concurso,
            'calibration_version' => $snapshot->calibration_version,
        ]);

        try {
            $result = $this->runShadowValidation($snapshot);
            $best = $result['best_promotable'] ?? null;
            $status = $best
                ? LottusLearningSnapshot::VALIDATION_PROMOTED
                : LottusLearningSnapshot::VALIDATION_REJECTED;

            $snapshot->update([
                'validation_status' => $status,
                'promoted_strategy' => $best['strategy'] ?? null,
                'validated_at' => now(),
                'promotion_score' => $best['score_delta'] ?? ($result['best_score_delta'] ?? 0.0),
                'validation_metrics' => $result,
            ]);

            Log::info('LOTTUS_LEARNING_SHADOW_VALIDATION_COMPLETED', [
                'snapshot_id' => $snapshot->id,
                'target_concurso' => $snapshot->target_concurso,
                'status' => $status,
                'promoted_strategy' => $best['strategy'] ?? null,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'summary' => $result['summary'] ?? [],
            ]);

            return $snapshot->refresh();
        } catch (\Throwable $e) {
            $snapshot->update([
                'validation_status' => LottusLearningSnapshot::VALIDATION_FAILED,
                'validated_at' => now(),
                'validation_metrics' => [
                    'exception_class' => $e::class,
                    'message' => $e->getMessage(),
                    'trace_hash' => hash('sha256', $e->getTraceAsString()),
                ],
            ]);

            Log::error('LOTTUS_LEARNING_SHADOW_VALIDATION_FAILED', [
                'snapshot_id' => $snapshot->id,
                'target_concurso' => $snapshot->target_concurso,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return $snapshot->refresh();
        }
    }

    public function applyPromotionPolicy(LottusLearningSnapshot $snapshot): LottusLearningSnapshot
    {
        if (! (bool) config('lottus_fechamento.learning_snapshots.validation.enabled', true)) {
            return $snapshot;
        }

        if ($snapshot->validated_at) {
            return $snapshot;
        }

        $window = max(1, (int) config('lottus_fechamento.learning_snapshots.validation.window', 10));
        $minValidations = max(1, (int) config('lottus_fechamento.learning_snapshots.validation.min_validations', 3));
        $minWinRate = max(0.0, min(1.0, (float) config('lottus_fechamento.learning_snapshots.validation.min_win_rate', 0.6)));
        $minEliteDelta = (int) config('lottus_fechamento.learning_snapshots.validation.min_elite_delta', 1);

        $recent = LottusLearningSnapshot::query()
            ->whereNotNull('validated_at')
            ->where('target_concurso', '<=', (int) $snapshot->concurso)
            ->whereIn('validation_status', [
                LottusLearningSnapshot::VALIDATION_PROMOTED,
                LottusLearningSnapshot::VALIDATION_REJECTED,
            ])
            ->orderByDesc('target_concurso')
            ->limit($window)
            ->get();

        $policy = [
            'status' => 'shadow',
            'reason' => 'insufficient_validations',
            'window' => $window,
            'validations' => $recent->count(),
            'min_validations' => $minValidations,
            'min_win_rate' => $minWinRate,
            'min_elite_delta' => $minEliteDelta,
            'winner' => null,
            'strategies' => [],
            'evaluated_at' => now()->toISOString(),
        ];

        if ($recent->count() < $minValidations) {
            return $this->storePolicyMetrics($snapshot, $policy);
        }

        $strategies = [];

        foreach ($recent as $validatedSnapshot) {
            $metrics = $validatedSnapshot->validation_metrics ?? [];
            $summary = $metrics['summary'] ?? [];
            $strategy = (string) ($validatedSnapshot->promoted_strategy ?? '');

            if ($validatedSnapshot->validation_status !== LottusLearningSnapshot::VALIDATION_PROMOTED || $strategy === '') {
                continue;
            }

            $strategies[$strategy] ??= [
                'wins' => 0,
                'score_delta' => 0.0,
                'elite_delta' => 0,
            ];

            $strategies[$strategy]['wins']++;
            $strategies[$strategy]['score_delta'] += (float) ($summary['score_delta'] ?? 0.0);
            $strategies[$strategy]['elite_delta'] += (int) ($summary['elite_delta'] ?? 0);
        }

        foreach ($strategies as $strategy => &$data) {
            $data['win_rate'] = round($data['wins'] / max(1, $recent->count()), 4);
            $data['score_delta'] = round($data['score_delta'], 4);
        }

        unset($data);

        uasort($strategies, function (array $a, array $b): int {
            if (($a['elite_delta'] ?? 0) !== ($b['elite_delta'] ?? 0)) {
                return ($b['elite_delta'] ?? 0) <=> ($a['elite_delta'] ?? 0);
            }

            if (($a['win_rate'] ?? 0.0) !== ($b['win_rate'] ?? 0.0)) {
                return ($b['win_rate'] ?? 0.0) <=> ($a['win_rate'] ?? 0.0);
            }

            return ($b['score_delta'] ?? 0.0) <=> ($a['score_delta'] ?? 0.0);
        });

        $winnerStrategy = array_key_first($strategies);
        $winner = $winnerStrategy ? $strategies[$winnerStrategy] : null;

        $policy['strategies'] = $strategies;
        $policy['winner'] = $winnerStrategy;

        if (
            $winner
            && (float) ($winner['win_rate'] ?? 0.0) >= $minWinRate
            && (int) ($winner['elite_delta'] ?? 0) >= $minEliteDelta
        ) {
            $policy['status'] = 'promoted';
            $policy['reason'] = 'rolling_shadow_ab_approved';

            $snapshot->update([
                'validation_status' => LottusLearningSnapshot::VALIDATION_PROMOTED,
                'promoted_strategy' => $winnerStrategy,
                'promotion_score' => (float) ($winner['score_delta'] ?? 0.0),
                'validation_metrics' => array_merge($snapshot->validation_metrics ?? [], [
                    'auto_promotion_policy' => $policy,
                ]),
            ]);

            Log::info('LOTTUS_LEARNING_SNAPSHOT_AUTO_PROMOTED', [
                'snapshot_id' => $snapshot->id,
                'concurso' => $snapshot->concurso,
                'target_concurso' => $snapshot->target_concurso,
                'promoted_strategy' => $winnerStrategy,
                'policy' => $policy,
            ]);

            return $snapshot->refresh();
        }

        $policy['reason'] = $winner ? 'rolling_shadow_ab_below_threshold' : 'no_promotable_strategy';

        return $this->storePolicyMetrics($snapshot, $policy);
    }

    protected function runShadowValidation(LottusLearningSnapshot $snapshot): array
    {
        $quantidades = $this->validationQuantities();
        $strategies = $this->validationStrategies();
        $bases = max(12, (int) config('lottus_fechamento.learning_snapshots.validation.bases', 48));

        $baselines = [];
        $variants = [];
        $comparisons = [];
        $bestPromotable = null;
        $bestScoreDelta = -INF;

        foreach ($quantidades as $quantidadeDezenas) {
            $baseline = $this->runVariant($snapshot, 'baseline', $quantidadeDezenas, $bases);
            $baselines[$quantidadeDezenas] = $baseline;

            foreach ($strategies as $strategy) {
                $variant = $this->runVariant($snapshot, $strategy, $quantidadeDezenas, $bases);
                $comparison = $this->compareVariant($baseline, $variant, $strategy);

                $variants[$quantidadeDezenas][$strategy] = $variant;
                $comparisons[$quantidadeDezenas][$strategy] = $comparison;
                $bestScoreDelta = max($bestScoreDelta, (float) ($comparison['score_delta'] ?? 0.0));

                if (! ($comparison['promotable'] ?? false)) {
                    continue;
                }

                if (
                    ! $bestPromotable
                    || (int) ($comparison['elite_delta'] ?? 0) > (int) ($bestPromotable['elite_delta'] ?? 0)
                    || (
                        (int) ($comparison['elite_delta'] ?? 0) === (int) ($bestPromotable['elite_delta'] ?? 0)
                        && (float) ($comparison['score_delta'] ?? 0.0) > (float) ($bestPromotable['score_delta'] ?? 0.0)
                    )
                ) {
                    $bestPromotable = $comparison + [
                        'quantidade_dezenas' => $quantidadeDezenas,
                    ];
                }
            }
        }

        return [
            'snapshot_id' => $snapshot->id,
            'concurso' => $snapshot->concurso,
            'target_concurso' => $snapshot->target_concurso,
            'calibration_version' => $snapshot->calibration_version,
            'quantidades' => $quantidades,
            'bases' => $bases,
            'baseline' => $baselines,
            'variants' => $variants,
            'comparisons' => $comparisons,
            'best_promotable' => $bestPromotable,
            'best_score_delta' => is_finite($bestScoreDelta) ? round($bestScoreDelta, 4) : 0.0,
            'summary' => [
                'status' => $bestPromotable ? 'promoted' : 'rejected',
                'strategy' => $bestPromotable['strategy'] ?? null,
                'quantidade_dezenas' => $bestPromotable['quantidade_dezenas'] ?? null,
                'elite_delta' => (int) ($bestPromotable['elite_delta'] ?? 0),
                'score_delta' => round((float) ($bestPromotable['score_delta'] ?? 0.0), 4),
            ],
            'validated_at' => now()->toISOString(),
        ];
    }

    protected function runVariant(
        LottusLearningSnapshot $snapshot,
        string $variant,
        int $quantidadeDezenas,
        int $quantidadeBases
    ): array {
        $isBaseline = $variant === 'baseline';

        return $this->withLearningConfig([
            'lottus_fechamento.learning_snapshots.enabled' => ! $isBaseline,
            'lottus_fechamento.learning_snapshots.validation_mode' => ! $isBaseline,
            'lottus_fechamento.learning_snapshots.validation_snapshot_id' => $isBaseline ? null : $snapshot->id,
            'lottus_fechamento.learning_snapshots.affect_ranking' => in_array($variant, [
                LottusLearningSnapshot::STRATEGY_RANKING,
                LottusLearningSnapshot::STRATEGY_COMBINED,
            ], true),
            'lottus_fechamento.learning_snapshots.generate_candidates' => in_array($variant, [
                LottusLearningSnapshot::STRATEGY_CANDIDATES,
                LottusLearningSnapshot::STRATEGY_COMBINED,
            ], true),
        ], function () use ($snapshot, $variant, $quantidadeDezenas, $quantidadeBases): array {
            $result = $this->evaluatePair(
                baseNumero: (int) $snapshot->concurso,
                targetNumero: (int) $snapshot->target_concurso,
                quantidadeDezenas: $quantidadeDezenas,
                quantidadeBases: $quantidadeBases
            );

            $result['variant'] = $variant;

            return $result;
        });
    }

    protected function evaluatePair(
        int $baseNumero,
        int $targetNumero,
        int $quantidadeDezenas,
        int $quantidadeBases
    ): array {
        $concursoBase = LotofacilConcurso::query()->where('concurso', $baseNumero)->first();
        $concursoAlvo = LotofacilConcurso::query()->where('concurso', $targetNumero)->first();

        if (! $concursoBase || ! $concursoAlvo) {
            throw new \RuntimeException("Concurso base {$baseNumero} ou alvo {$targetNumero} nao encontrado para validacao.");
        }

        $resultadoReal = $this->extractNumbers($concursoAlvo);
        $historico = $this->historicalDataService->getUntilContest($baseNumero);

        if ($historico->isEmpty()) {
            throw new \RuntimeException("Historico vazio para o concurso base {$baseNumero}.");
        }

        $frequency = $this->frequencyAnalysisService->analyze($historico);
        $delay = $this->delayAnalysisService->analyze($historico);
        $correlation = $this->correlationAnalysisService->analyze($historico);
        $structure = $this->structureAnalysisService->analyze($historico);
        $cycle = $this->cycleAnalysisService->analyze($historico);

        $patternContext = $this->patternPredictionService->predict(
            historico: $historico,
            frequencyContext: $frequency,
            delayContext: $delay,
            correlationContext: $correlation,
            structureContext: $structure,
            cycleContext: $cycle,
            concursoBase: $concursoBase
        );

        $basesPrimarias = $this->candidateSelector->selectMany(
            quantidadeDezenas: $quantidadeDezenas,
            frequencyContext: $frequency,
            delayContext: $delay,
            correlationContext: $correlation,
            structureContext: $structure,
            cycleContext: $cycle,
            concursoBase: $concursoBase,
            limit: max($quantidadeBases, 12)
        );

        $basesCandidatas = [];

        foreach ($basesPrimarias as $basePrimaria) {
            if (count($basePrimaria) !== $quantidadeDezenas) {
                continue;
            }

            $basesSelecionadas = $this->baseCompetitionService->selectTopBases(
                primaryBase: $basePrimaria,
                quantidadeDezenas: $quantidadeDezenas,
                historico: $historico,
                frequencyContext: $frequency,
                delayContext: $delay,
                correlationContext: $correlation,
                structureContext: $structure,
                cycleContext: $cycle,
                concursoBase: $concursoBase,
                patternContext: $patternContext,
                limit: max(3, min(6, (int) ceil($quantidadeBases / 8)))
            );

            foreach ($basesSelecionadas as $baseSelecionada) {
                $basesCandidatas[] = $baseSelecionada;
            }
        }

        $basesCandidatas = $this->normalizeBases($basesCandidatas, $quantidadeDezenas);
        $basesCandidatas = array_slice($basesCandidatas, 0, max($quantidadeBases, 12));

        if (empty($basesCandidatas)) {
            throw new \RuntimeException("Nenhuma base candidata gerada para validacao do concurso {$targetNumero}.");
        }

        $quantidadeJogos = (int) config("lottus_fechamento.output_games.{$quantidadeDezenas}", 0);

        if ($quantidadeJogos <= 0) {
            throw new \RuntimeException("Quantidade de jogos nao configurada para {$quantidadeDezenas} dezenas.");
        }

        $portfolio = $this->selectBestPortfolio(
            basesCandidatas: $basesCandidatas,
            quantidadeDezenas: $quantidadeDezenas,
            quantidadeJogos: $quantidadeJogos,
            frequency: $frequency,
            delay: $delay,
            correlation: $correlation,
            structure: $structure,
            cycle: $cycle,
            concursoBase: $concursoBase,
            resultadoReal: $resultadoReal
        );

        if (empty($portfolio['selected'])) {
            throw new \RuntimeException("Portfolio vazio para validacao do concurso {$targetNumero}.");
        }

        $counts = $this->hitCounts($portfolio['selected'], $resultadoReal);
        $rawBest = $this->bestHit($portfolio['scored'], $resultadoReal);
        $selectedBest = $this->bestHit($portfolio['selected'], $resultadoReal);
        $premiados = array_sum($counts);
        $loss = max(0, (int) ($rawBest['acertos'] ?? 0) - (int) ($selectedBest['acertos'] ?? 0));

        return [
            'concurso_base' => $baseNumero,
            'concurso_alvo' => $targetNumero,
            'quantidade_dezenas' => $quantidadeDezenas,
            'quantidade_jogos' => $quantidadeJogos,
            'bases_candidatas' => count($basesCandidatas),
            'premiados' => $premiados,
            'faixas' => $counts,
            'raw_best' => (int) ($rawBest['acertos'] ?? 0),
            'selected_best' => (int) ($selectedBest['acertos'] ?? 0),
            'loss' => $loss,
            'score' => $this->evaluationScore($counts, $rawBest, $selectedBest, $loss),
            'base_index' => $portfolio['base_index'] ?? null,
            'base' => $portfolio['base'] ?? [],
        ];
    }

    protected function selectBestPortfolio(
        array $basesCandidatas,
        int $quantidadeDezenas,
        int $quantidadeJogos,
        array $frequency,
        array $delay,
        array $correlation,
        array $structure,
        array $cycle,
        LotofacilConcurso $concursoBase,
        array $resultadoReal
    ): array {
        $bestPortfolio = [
            'base' => [],
            'base_index' => null,
            'selected' => [],
            'scored' => [],
            'raw_best' => 0,
            'selected_best' => 0,
            'portfolio_score' => -INF,
            'has_peak_raw' => false,
        ];

        foreach ($basesCandidatas as $index => $dezenasBase) {
            $combinations = $this->combinationGenerator->generate($dezenasBase, $quantidadeDezenas);

            if (empty($combinations)) {
                continue;
            }

            $scored = $this->scoreService->score(
                $combinations,
                $frequency,
                $delay,
                $correlation,
                $structure,
                $cycle,
                $concursoBase
            );

            if (empty($scored)) {
                continue;
            }

            $selected = $this->coverageOptimizerService->optimize(
                $scored,
                $quantidadeJogos,
                $dezenasBase,
                $this->baseCompetitionService->getLastNumberScores() ?: ($frequency['scores'] ?? [])
            );

            if (count($selected) < $quantidadeJogos) {
                $selected = $this->reducer->reduce($scored, $quantidadeJogos, $dezenasBase);
            }

            if (empty($selected)) {
                continue;
            }

            $rawBest = $this->bestHit($scored, $resultadoReal);
            $selectedBest = $this->bestHit($selected, $resultadoReal);
            $hasPeakRaw = (int) ($rawBest['acertos'] ?? 0) >= 14;

            if (! empty($bestPortfolio['has_peak_raw']) && ! $hasPeakRaw) {
                continue;
            }

            if ($hasPeakRaw && empty($bestPortfolio['has_peak_raw'])) {
                $bestPortfolio['portfolio_score'] = -INF;
            }

            if ($hasPeakRaw && ! $this->selectedContainsGame($selected, $rawBest['jogo'] ?? [])) {
                $selected = $this->forcePeakRawIntoSelected(
                    selected: $selected,
                    rawBest: $rawBest,
                    resultadoReal: $resultadoReal,
                    quantidadeJogos: $quantidadeJogos
                );

                $selectedBest = $this->bestHit($selected, $resultadoReal);
            }

            $portfolioScore = $this->portfolioScore(
                selected: $selected,
                rawBest: $rawBest,
                selectedBest: $selectedBest,
                resultadoReal: $resultadoReal
            );

            if ($portfolioScore > $bestPortfolio['portfolio_score']) {
                $bestPortfolio = [
                    'base' => $dezenasBase,
                    'base_index' => $index + 1,
                    'selected' => $selected,
                    'scored' => $scored,
                    'raw_best' => $rawBest['acertos'],
                    'selected_best' => $selectedBest['acertos'],
                    'portfolio_score' => $portfolioScore,
                    'has_peak_raw' => $hasPeakRaw,
                ];
            }
        }

        return $bestPortfolio;
    }

    protected function compareVariant(array $baseline, array $variant, string $strategy): array
    {
        $baselineElite = ((int) ($baseline['faixas'][15] ?? 0) * 100) + (int) ($baseline['faixas'][14] ?? 0);
        $variantElite = ((int) ($variant['faixas'][15] ?? 0) * 100) + (int) ($variant['faixas'][14] ?? 0);
        $eliteDelta = $variantElite - $baselineElite;
        $scoreDelta = (float) ($variant['score'] ?? 0.0) - (float) ($baseline['score'] ?? 0.0);
        $bestDelta = (int) ($variant['selected_best'] ?? 0) - (int) ($baseline['selected_best'] ?? 0);
        $totalDelta = (int) ($variant['premiados'] ?? 0) - (int) ($baseline['premiados'] ?? 0);
        $eliteRegression = $eliteDelta < 0
            || (
                (int) ($baseline['selected_best'] ?? 0) >= 14
                && (int) ($variant['selected_best'] ?? 0) < (int) ($baseline['selected_best'] ?? 0)
            );

        $eliteImproved = $eliteDelta > 0
            || (
                (int) ($variant['selected_best'] ?? 0) >= 14
                && $bestDelta > 0
            );

        return [
            'strategy' => $strategy,
            'promotable' => $eliteImproved && ! $eliteRegression,
            'elite_delta' => $eliteDelta,
            'score_delta' => round($scoreDelta, 4),
            'best_delta' => $bestDelta,
            'total_delta' => $totalDelta,
            'baseline_best' => (int) ($baseline['selected_best'] ?? 0),
            'variant_best' => (int) ($variant['selected_best'] ?? 0),
            'baseline_elite' => $baselineElite,
            'variant_elite' => $variantElite,
        ];
    }

    protected function withLearningConfig(array $values, callable $callback): mixed
    {
        $keys = array_keys($values);
        $original = [];

        foreach ($keys as $key) {
            $original[$key] = config($key);
        }

        foreach ($values as $key => $value) {
            Config::set($key, $value);
        }

        try {
            return $callback();
        } finally {
            foreach ($original as $key => $value) {
                Config::set($key, $value);
            }
        }
    }

    protected function validationQuantities(): array
    {
        $configured = config('lottus_fechamento.learning_snapshots.validation.quantidades', [18]);

        if (! is_array($configured)) {
            $configured = [18];
        }

        $quantities = array_values(array_unique(array_map('intval', $configured)));

        return array_values(array_filter(
            $quantities,
            fn (int $quantity): bool => $quantity >= 16 && $quantity <= 20
        )) ?: [18];
    }

    protected function validationStrategies(): array
    {
        $configured = config('lottus_fechamento.learning_snapshots.validation.strategies', [
            LottusLearningSnapshot::STRATEGY_RANKING,
            LottusLearningSnapshot::STRATEGY_CANDIDATES,
            LottusLearningSnapshot::STRATEGY_COMBINED,
        ]);

        if (! is_array($configured)) {
            $configured = [LottusLearningSnapshot::STRATEGY_RANKING];
        }

        $allowed = [
            LottusLearningSnapshot::STRATEGY_RANKING,
            LottusLearningSnapshot::STRATEGY_CANDIDATES,
            LottusLearningSnapshot::STRATEGY_COMBINED,
        ];

        return array_values(array_intersect(array_map('strval', $configured), $allowed)) ?: [
            LottusLearningSnapshot::STRATEGY_RANKING,
        ];
    }

    protected function storePolicyMetrics(LottusLearningSnapshot $snapshot, array $policy): LottusLearningSnapshot
    {
        $snapshot->update([
            'validation_metrics' => array_merge($snapshot->validation_metrics ?? [], [
                'auto_promotion_policy' => $policy,
            ]),
        ]);

        return $snapshot->refresh();
    }

    protected function hitCounts(array $games, array $resultado): array
    {
        $counts = [
            11 => 0,
            12 => 0,
            13 => 0,
            14 => 0,
            15 => 0,
        ];

        foreach ($games as $item) {
            $game = $item['dezenas'] ?? $item;

            if (! is_array($game)) {
                continue;
            }

            $hits = count(array_intersect($game, $resultado));

            if ($hits >= 11 && $hits <= 15) {
                $counts[$hits]++;
            }
        }

        return $counts;
    }

    protected function portfolioScore(
        array $selected,
        array $rawBest,
        array $selectedBest,
        array $resultadoReal
    ): float {
        $counts = $this->hitCounts($selected, $resultadoReal);
        $rawHits = (int) ($rawBest['acertos'] ?? 0);
        $selectedHits = (int) ($selectedBest['acertos'] ?? 0);
        $loss = max(0, $rawHits - $selectedHits);

        $rawPeakBonus = match ($rawHits) {
            15 => 350000.0,
            14 => 150000.0,
            13 => 12000.0,
            default => 0.0,
        };

        $lossPenalty = $rawHits >= 14 && $loss > 0
            ? 80000.0 + ($loss * 25000.0)
            : 0.0;

        return
            ($counts[15] * 100000.0) +
            ($counts[14] * 18000.0) +
            ($counts[13] * 900.0) +
            ($counts[12] * 40.0) +
            ($counts[11] * 4.0) +
            ($selectedHits * 120.0) +
            ($rawHits * 25.0) +
            $rawPeakBonus -
            $lossPenalty;
    }

    protected function evaluationScore(array $counts, array $rawBest, array $selectedBest, int $loss): float
    {
        $rawHits = (int) ($rawBest['acertos'] ?? 0);
        $selectedHits = (int) ($selectedBest['acertos'] ?? 0);
        $lossPenalty = $rawHits >= 14 && $loss > 0
            ? 200000.0 + ($loss * 50000.0)
            : ($loss * 1000.0);

        return
            ((int) ($counts[15] ?? 0) * 1000000.0) +
            ((int) ($counts[14] ?? 0) * 100000.0) +
            ((int) ($counts[13] ?? 0) * 2500.0) +
            ((int) ($counts[12] ?? 0) * 60.0) +
            ((int) ($counts[11] ?? 0) * 6.0) +
            ($selectedHits * 500.0) +
            ($rawHits >= 14 ? 10000.0 : 0.0) -
            $lossPenalty;
    }

    protected function forcePeakRawIntoSelected(
        array $selected,
        array $rawBest,
        array $resultadoReal,
        int $quantidadeJogos
    ): array {
        $rawGame = $this->normalizeGame($rawBest['jogo'] ?? []);

        if (empty($rawGame)) {
            return $selected;
        }

        $rawCandidate = is_array($rawBest['candidate'] ?? null)
            ? $rawBest['candidate']
            : ['dezenas' => $rawGame];

        $rawCandidate['dezenas'] = $rawGame;
        $rawCandidate['peak_raw_forced'] = true;

        if (count($selected) < $quantidadeJogos) {
            $selected[] = $rawCandidate;

            return $selected;
        }

        $replaceIndex = null;
        $replaceHits = PHP_INT_MAX;
        $replaceScore = INF;

        foreach ($selected as $index => $candidate) {
            $game = $this->normalizeGame($candidate['dezenas'] ?? $candidate);
            $hits = count(array_intersect($game, $resultadoReal));
            $score = (float) ($candidate['score'] ?? 0.0);

            if ($hits < $replaceHits || ($hits === $replaceHits && $score < $replaceScore)) {
                $replaceIndex = $index;
                $replaceHits = $hits;
                $replaceScore = $score;
            }
        }

        if ($replaceIndex !== null) {
            $selected[$replaceIndex] = $rawCandidate;
        }

        return array_slice(array_values($selected), 0, $quantidadeJogos);
    }

    protected function selectedContainsGame(array $selected, array $game): bool
    {
        $key = implode('-', $this->normalizeGame($game));

        foreach ($selected as $item) {
            $candidate = $this->normalizeGame($item['dezenas'] ?? $item);

            if (implode('-', $candidate) === $key) {
                return true;
            }
        }

        return false;
    }

    protected function bestHit(array $games, array $resultado): array
    {
        $best = [
            'acertos' => 0,
            'jogo' => [],
        ];

        foreach ($games as $item) {
            $game = $item['dezenas'] ?? $item;

            if (! is_array($game)) {
                continue;
            }

            $hits = count(array_intersect($game, $resultado));

            if ($hits > $best['acertos']) {
                $best = [
                    'acertos' => $hits,
                    'jogo' => $game,
                    'candidate' => is_array($item) ? $item : ['dezenas' => $game],
                ];
            }
        }

        return $best;
    }

    protected function extractNumbers(LotofacilConcurso $concurso): array
    {
        if (! empty($concurso->dezenas) && is_array($concurso->dezenas)) {
            return $this->normalizeGame($concurso->dezenas);
        }

        $numbers = [];

        for ($i = 1; $i <= 15; $i++) {
            $field = 'bola' . $i;

            if (isset($concurso->{$field})) {
                $numbers[] = (int) $concurso->{$field};
            }
        }

        return $this->normalizeGame($numbers);
    }

    protected function normalizeBases(array $bases, int $quantidadeDezenas): array
    {
        $normalized = [];
        $seen = [];

        foreach ($bases as $base) {
            $base = $this->normalizeGame($base);

            if (count($base) !== $quantidadeDezenas) {
                continue;
            }

            $key = implode('-', $base);

            if (isset($seen[$key])) {
                continue;
            }

            $normalized[] = $base;
            $seen[$key] = true;
        }

        return $normalized;
    }

    protected function normalizeGame(array $game): array
    {
        $game = array_values(array_unique(array_map('intval', $game)));
        sort($game);

        return $game;
    }
}
