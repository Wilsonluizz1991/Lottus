<?php

namespace App\Services\Lottus\MainLearning;

use App\Models\LottusMainLearningAdjustment;
use App\Models\LottusMainLearningRun;
use App\Models\LottusMainLearningSnapshot;
use App\Models\LottusMainStrategyPerformance;
use App\Services\Lottus\Data\HistoricalDataService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LottusMainAdaptiveLearningService
{
    public function __construct(
        protected HistoricalDataService $historicalDataService,
        protected LottusMainTrendDetectionService $trendDetectionService,
        protected LottusMainLearningEvaluationService $evaluationService,
        protected LottusMainBehaviorAnalysisService $behaviorAnalysisService,
        protected LottusMainLearningPromotionService $promotionService,
        protected AggressivenessCalibrationEngine $aggressivenessEngine,
        protected LottusMainPortfolioCalibrationService $portfolioCalibrationService
    ) {
    }

    public function enqueue(int $concurso, bool $force = false): ?LottusMainLearningRun
    {
        if (! (bool) config('lottus_main_learning.enabled', true)) {
            return null;
        }

        $existing = LottusMainLearningRun::query()->where('concurso', $concurso)->first();

        if ($existing && ! $force) {
            return $existing;
        }

        if ($existing && $force) {
            $existing->update([
                'status' => LottusMainLearningRun::STATUS_PENDING,
                'started_at' => null,
                'finished_at' => null,
                'duration_ms' => null,
                'baseline_metrics_json' => null,
                'learned_metrics_json' => null,
                'delta_metrics_json' => null,
                'decision' => null,
                'error_message' => null,
            ]);

            return $existing->refresh();
        }

        return LottusMainLearningRun::query()->create([
            'concurso' => $concurso,
            'status' => LottusMainLearningRun::STATUS_PENDING,
        ]);
    }

    public function processRun(int $runId, bool $validateOnly = false): LottusMainLearningRun
    {
        $run = LottusMainLearningRun::query()->findOrFail($runId);
        $started = microtime(true);

        $run->update([
            'status' => LottusMainLearningRun::STATUS_PROCESSING,
            'started_at' => now(),
            'error_message' => null,
        ]);

        try {
            $lookback = (int) config('lottus_main_learning.validation_lookback', 36);
            $inicio = max(1, $run->concurso - $lookback);
            $fim = $run->concurso;
            $jogos = 10;
            $baselineSummary = $this->evaluationService->baselineSummary($inicio, $fim, $jogos);
            $payload = $this->buildPayloadForContest($run->concurso, $baselineSummary);
            $selectedEvaluation = $this->bestValidatedPayload($inicio, $fim, $jogos, $payload, $baselineSummary);
            $payload = $selectedEvaluation['payload'];
            $comparison = $selectedEvaluation['comparison'];
            $decision = $this->promotionService->decide(
                $comparison['baseline_metrics'],
                $comparison['learned_metrics'],
                $comparison['delta']
            );

            DB::transaction(function () use ($run, $payload, $comparison, $decision, $started, $validateOnly): void {
                $snapshot = null;

                if (! $validateOnly) {
                    $snapshot = $this->storeSnapshot($run->concurso, $payload, $comparison, $decision);
                    $this->storeAdjustments($snapshot, $payload);
                    $this->storeStrategyPerformance($run->concurso, $comparison['learned_summary']);
                }

                $run->update([
                    'status' => LottusMainLearningRun::STATUS_COMPLETED,
                    'finished_at' => now(),
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                    'baseline_metrics_json' => $comparison['baseline_metrics'],
                    'learned_metrics_json' => $comparison['learned_metrics'],
                    'delta_metrics_json' => array_merge($comparison['delta'], [
                        'snapshot_id' => $snapshot?->id,
                        'snapshot_status' => $snapshot?->status,
                        'confidence' => $decision['confidence'] ?? 0,
                        'reason' => $decision['reason'] ?? null,
                        'payload_variant' => $payload['_variant'] ?? 'combined',
                    ]),
                    'decision' => $validateOnly ? 'validate_only' : ($decision['decision'] ?? 'pending'),
                ]);
            });

            Log::info('LOTTUS_MAIN_LEARNING_COMPLETED', [
                'run_id' => $run->id,
                'concurso' => $run->concurso,
                'decision' => $decision['decision'] ?? null,
                'confidence' => $decision['confidence'] ?? null,
            ]);

            return $run->refresh();
        } catch (\Throwable $e) {
            $run->update([
                'status' => LottusMainLearningRun::STATUS_FAILED,
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'error_message' => $e->getMessage(),
            ]);

            Log::error('LOTTUS_MAIN_LEARNING_FAILED', [
                'run_id' => $run->id,
                'concurso' => $run->concurso,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            throw $e;
        }
    }

    protected function bestValidatedPayload(int $inicio, int $fim, int $jogos, array $payload, array $baselineSummary): array
    {
        $variants = [
            'portfolio_only' => $this->portfolioOnlyPayload($payload),
        ];

        if ((bool) config('lottus_main_learning.evaluate_combined_variant', false)) {
            $variants = ['combined' => $payload] + $variants;
        }

        $best = null;

        foreach ($variants as $name => $variantPayload) {
            $variantPayload['_variant'] = $name;
            $comparison = $this->evaluationService->compareFixedPayload(
                $inicio,
                $fim,
                $jogos,
                $variantPayload,
                $baselineSummary
            );
            $score = $this->variantScore($comparison['delta'], $comparison['learned_metrics']);

            if ($best === null || $score > $best['score']) {
                $best = [
                    'payload' => $variantPayload,
                    'comparison' => $comparison,
                    'score' => $score,
                ];
            }
        }

        return $best ?? [
            'payload' => $payload,
            'comparison' => $this->evaluationService->compareFixedPayload($inicio, $fim, $jogos, $payload, $baselineSummary),
            'score' => 0.0,
        ];
    }

    protected function portfolioOnlyPayload(array $payload): array
    {
        return [
            'version' => $payload['version'] ?? 1,
            'generated_at' => $payload['generated_at'] ?? now()->toISOString(),
            'sample_size' => $payload['sample_size'] ?? 0,
            'number_bias' => [],
            'pair_bias' => [],
            'structure_bias' => [],
            'strategy_weights' => [],
            'score_adjustments' => [],
            'raw_elite_protection' => $payload['raw_elite_protection'] ?? ['enabled' => true],
            'portfolio_rules' => $payload['portfolio_rules'] ?? [],
            'portfolio_calibration' => $payload['portfolio_calibration'] ?? [],
            'trend_metrics' => array_merge($payload['trend_metrics'] ?? [], [
                'variant' => 'portfolio_only',
                'reason' => 'portfolio_rules_validated_without_score_or_generation_bias',
            ]),
        ];
    }

    protected function variantScore(array $delta, array $learnedMetrics): float
    {
        return ((float) ($delta['selected15'] ?? 0) * 6000.0)
            + ((float) ($delta['raw15'] ?? 0) * 3000.0)
            + ((float) ($delta['selected14'] ?? 0) * 1800.0)
            + ((float) ($delta['raw14'] ?? 0) * 600.0)
            + ((float) ($delta['near15'] ?? 0) * 180.0)
            + ((float) ($delta['raw_14_15_preservados'] ?? 0) * 1400.0)
            - ((float) ($delta['loss14_15'] ?? 0) * 1600.0)
            - max(0.0, (float) ($learnedMetrics['loss14_15'] ?? 0) * 80.0);
    }

    public function buildPayloadForContest(int $concurso, ?array $baselineSummary = null): array
    {
        $historico = $this->historicalDataService->getUntilContest($concurso);
        $previous = LottusMainLearningSnapshot::query()
            ->where('status', LottusMainLearningSnapshot::STATUS_PROMOTED)
            ->where('concurso_base', '<', $concurso)
            ->orderByDesc('concurso_base')
            ->first();
        $previousPayload = $previous?->payload_json ?? [];
        $payload = $this->trendDetectionService->detect($historico, $previousPayload);
        $baselineSummary ??= $this->recentBaselineSummary($concurso);
        $baselineMetrics = $this->behaviorAnalysisService->metricsFromBacktest($baselineSummary);
        $portfolioCalibration = $this->portfolioCalibrationService->calibrate($baselineSummary, $previousPayload);
        $payload['portfolio_rules'] = array_replace_recursive(
            $payload['portfolio_rules'] ?? [],
            $portfolioCalibration['portfolio_rules'] ?? []
        );
        $payload['portfolio_calibration'] = $portfolioCalibration['metrics'] ?? [];
        $payload['aggressiveness'] = $this->aggressivenessEngine->calibrate($baselineMetrics, $payload, $previousPayload);

        return $payload;
    }

    protected function recentBaselineMetrics(int $concurso): array
    {
        $lookback = min(20, (int) config('lottus_main_learning.validation_lookback', 36));
        $inicio = max(1, $concurso - $lookback);

        try {
            return $this->evaluationService->baselineMetrics($inicio, $concurso, 10);
        } catch (\Throwable) {
            return [
                'concursos' => 0,
                'raw14' => 0,
                'raw15' => 0,
                'selected14' => 0,
                'selected15' => 0,
                'near15' => 0,
                'loss14_15' => 0,
            ];
        }
    }

    protected function recentBaselineSummary(int $concurso): array
    {
        $lookback = min(20, (int) config('lottus_main_learning.validation_lookback', 36));
        $inicio = max(1, $concurso - $lookback);

        try {
            return $this->evaluationService->baselineSummary($inicio, $concurso, 10);
        } catch (\Throwable) {
            return [
                'concursos_testados' => 0,
                'jogos_gerados' => 0,
                'faixas' => [11 => 0, 12 => 0, 13 => 0, 14 => 0, 15 => 0],
                'raw_melhor_faixas' => [11 => 0, 12 => 0, 13 => 0, 14 => 0, 15 => 0],
                'raw_14_15_total' => 0,
                'raw_14_15_preservados' => 0,
                'raw_14_15_loss' => 0,
                'near_15_raw_candidates' => 0,
                'raw_15_candidates' => 0,
                'diagnostico' => [],
            ];
        }
    }

    protected function storeSnapshot(int $concurso, array $payload, array $comparison, array $decision): LottusMainLearningSnapshot
    {
        $latestVersion = (int) LottusMainLearningSnapshot::query()
            ->where('concurso_base', $concurso)
            ->max('version');

        return LottusMainLearningSnapshot::query()->create([
            'concurso_base' => $concurso,
            'target_concurso' => $concurso + 1,
            'status' => $decision['status'] ?? LottusMainLearningSnapshot::STATUS_PENDING,
            'version' => $latestVersion + 1,
            'payload_json' => $payload,
            'metrics_json' => [
                'baseline' => $comparison['baseline_metrics'],
                'learned' => $comparison['learned_metrics'],
                'delta' => $comparison['delta'],
                'decision' => $decision,
            ],
            'confidence' => (float) ($decision['confidence'] ?? 0.0),
        ]);
    }

    protected function storeAdjustments(LottusMainLearningSnapshot $snapshot, array $payload): void
    {
        $adjustments = [];

        foreach (($payload['number_bias'] ?? []) as $key => $value) {
            if (abs((float) $value) < 0.001) {
                continue;
            }

            $adjustments[] = [
                'snapshot_id' => $snapshot->id,
                'type' => 'number_bias',
                'key' => (string) $key,
                'old_value' => 0,
                'new_value' => (float) $value,
                'delta' => (float) $value,
                'reason' => 'drift_curto_medio_longo',
                'confidence' => $snapshot->confidence,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (($payload['pair_bias'] ?? []) as $key => $value) {
            if (abs((float) $value) < 0.003) {
                continue;
            }

            $adjustments[] = [
                'snapshot_id' => $snapshot->id,
                'type' => 'pair_bias',
                'key' => (string) $key,
                'old_value' => 0,
                'new_value' => (float) $value,
                'delta' => (float) $value,
                'reason' => 'sinergia_de_pares_recente',
                'confidence' => $snapshot->confidence,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (($payload['strategy_weights'] ?? []) as $key => $value) {
            $delta = (float) $value - 1.0;

            if (abs($delta) < 0.001) {
                continue;
            }

            $adjustments[] = [
                'snapshot_id' => $snapshot->id,
                'type' => 'strategy_weight',
                'key' => (string) $key,
                'old_value' => 1.0,
                'new_value' => (float) $value,
                'delta' => $delta,
                'reason' => 'realocacao_probabilistica_shadow',
                'confidence' => $snapshot->confidence,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (($payload['portfolio_calibration']['absolute_targets'] ?? []) as $target) {
            $rank = is_array($target) ? (int) ($target['rank'] ?? 0) : (int) $target;

            if ($rank < 1) {
                continue;
            }

            $adjustments[] = [
                'snapshot_id' => $snapshot->id,
                'type' => 'portfolio_rank_target',
                'key' => (string) $rank,
                'old_value' => 0,
                'new_value' => $rank,
                'delta' => $rank,
                'reason' => 'raw_14_rank_loss_calibration',
                'confidence' => $snapshot->confidence,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($adjustments, 500) as $chunk) {
            LottusMainLearningAdjustment::query()->insert($chunk);
        }
    }

    protected function storeStrategyPerformance(int $concurso, array $summary): void
    {
        foreach (($summary['strategy_stats'] ?? []) as $strategy => $stats) {
            LottusMainStrategyPerformance::query()->updateOrCreate(
                [
                    'concurso' => $concurso,
                    'strategy_name' => $strategy,
                    'jogos' => (int) ($summary['quantidade_jogos_por_concurso'] ?? 10),
                ],
                [
                    'raw14' => (int) ($stats['raw_14'] ?? 0),
                    'raw15' => (int) ($stats['raw_15'] ?? 0),
                    'selected14' => (int) ($summary['faixas'][14] ?? 0),
                    'selected15' => (int) ($summary['faixas'][15] ?? 0),
                    'near15' => (int) ($summary['near_15_raw_candidates'] ?? 0),
                    'loss14' => (int) ($summary['raw_14_15_loss'] ?? 0),
                    'loss15' => 0,
                    'elite_score' => (float) app(LottusMainBehaviorAnalysisService::class)
                        ->metricsFromBacktest($summary)['elite_score'],
                    'payload_json' => $stats,
                ]
            );
        }
    }
}
