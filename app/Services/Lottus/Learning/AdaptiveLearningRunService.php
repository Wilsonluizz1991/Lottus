<?php

namespace App\Services\Lottus\Learning;

use App\Models\LotofacilConcurso;
use App\Models\LottusLearningRun;
use App\Models\LottusLearningSnapshot;
use App\Models\MotorLearningLog;
use App\Models\MotorLearningWeight;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdaptiveLearningRunService
{
    public function __construct(
        protected LearningEngine $learningEngine,
        protected AdaptiveLearningPromotionService $promotionService
    ) {
    }

    public function enqueue(int $concurso, string $triggeredBy = 'auto', bool $force = false): ?LottusLearningRun
    {
        return DB::transaction(function () use ($concurso, $triggeredBy, $force): ?LottusLearningRun {
            $latestRun = LottusLearningRun::query()
                ->where('concurso', $concurso)
                ->orderByDesc('calibration_version')
                ->lockForUpdate()
                ->first();

            if (! $force && $latestRun) {
                if (in_array($latestRun->status, [
                    LottusLearningRun::STATUS_PENDING,
                    LottusLearningRun::STATUS_PROCESSING,
                    LottusLearningRun::STATUS_COMPLETED,
                ], true)) {
                    Log::info('LOTTUS_ADAPTIVE_LEARNING_SKIPPED_ALREADY_SCHEDULED', [
                        'concurso' => $concurso,
                        'run_id' => $latestRun->id,
                        'status' => $latestRun->status,
                        'calibration_version' => $latestRun->calibration_version,
                    ]);

                    return $latestRun;
                }
            }

            $nextVersion = $latestRun
                ? ((int) $latestRun->calibration_version) + 1
                : 1;

            return LottusLearningRun::query()->create([
                'concurso' => $concurso,
                'status' => LottusLearningRun::STATUS_PENDING,
                'calibration_version' => $nextVersion,
                'triggered_by' => $triggeredBy,
            ]);
        });
    }

    public function processRun(int $runId): LottusLearningRun
    {
        $run = LottusLearningRun::query()->findOrFail($runId);

        if ($run->status === LottusLearningRun::STATUS_COMPLETED) {
            return $run;
        }

        $concurso = LotofacilConcurso::query()
            ->where('concurso', $run->concurso)
            ->firstOrFail();

        $startedAt = now();
        $startedMicrotime = microtime(true);
        $weightsBefore = $this->learningWeightsSnapshot();
        $logsBefore = MotorLearningLog::query()
            ->where('engine', 'fechamento')
            ->where('concurso', $run->concurso)
            ->count();

        $run->update([
            'status' => LottusLearningRun::STATUS_PROCESSING,
            'started_at' => $startedAt,
            'finished_at' => null,
            'duration_ms' => null,
            'adjustments_count' => 0,
            'metrics_json' => null,
            'error_message' => null,
        ]);

        Log::info('LOTTUS_ADAPTIVE_LEARNING_STARTED', [
            'run_id' => $run->id,
            'concurso' => $run->concurso,
            'calibration_version' => $run->calibration_version,
            'triggered_by' => $run->triggered_by,
        ]);

        try {
            $validationSummary = $this->validatePreviousSnapshot($run);
            $learningSummary = $this->learningEngine->learnFromContest($concurso);

            $weightsAfter = $this->learningWeightsSnapshot();
            $logsAfter = MotorLearningLog::query()
                ->where('engine', 'fechamento')
                ->where('concurso', $run->concurso)
                ->count();

            $snapshot = $this->storeSnapshot(
                run: $run,
                weightsAfter: $weightsAfter
            );

            $snapshot = $this->promotionService->applyPromotionPolicy($snapshot);
            $promotionSummary = ($snapshot->validation_metrics ?? [])['auto_promotion_policy'] ?? null;

            $metrics = $this->buildMetrics(
                weightsBefore: $weightsBefore,
                weightsAfter: $weightsAfter,
                logsCreated: max(0, $logsAfter - $logsBefore),
                snapshotId: $snapshot->id,
                learningSummary: $learningSummary,
                validationSummary: $validationSummary,
                promotionSummary: $promotionSummary
            );

            $finishedAt = now();
            $durationMs = (int) round((microtime(true) - $startedMicrotime) * 1000);
            $adjustmentsCount = (int) ($metrics['adjustments_count'] ?? 0);

            $run->update([
                'status' => LottusLearningRun::STATUS_COMPLETED,
                'finished_at' => $finishedAt,
                'duration_ms' => $durationMs,
                'adjustments_count' => $adjustmentsCount,
                'metrics_json' => $metrics,
                'error_message' => null,
            ]);

            Log::info('LOTTUS_ADAPTIVE_LEARNING_COMPLETED', [
                'run_id' => $run->id,
                'concurso' => $run->concurso,
                'calibration_version' => $run->calibration_version,
                'duration_ms' => $durationMs,
                'adjustments_count' => $adjustmentsCount,
                'metrics' => $metrics,
            ]);

            return $run->refresh();
        } catch (\Throwable $e) {
            $finishedAt = now();
            $durationMs = (int) round((microtime(true) - $startedMicrotime) * 1000);

            $run->update([
                'status' => LottusLearningRun::STATUS_FAILED,
                'finished_at' => $finishedAt,
                'duration_ms' => $durationMs,
                'error_message' => $e->getMessage(),
                'metrics_json' => [
                    'exception_class' => $e::class,
                    'trace_hash' => hash('sha256', $e->getTraceAsString()),
                ],
            ]);

            Log::error('LOTTUS_ADAPTIVE_LEARNING_FAILED', [
                'run_id' => $run->id,
                'concurso' => $run->concurso,
                'calibration_version' => $run->calibration_version,
                'duration_ms' => $durationMs,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            throw $e;
        }
    }

    protected function learningWeightsSnapshot(): array
    {
        return MotorLearningWeight::query()
            ->where('engine', 'fechamento')
            ->get()
            ->mapWithKeys(function (MotorLearningWeight $weight): array {
                return [
                    (string) $weight->strategy => [
                        'weights' => $weight->weights ?? [],
                        'samples' => (int) $weight->samples,
                        'last_concurso' => $weight->last_concurso,
                        'last_error' => $weight->last_error,
                        'last_score' => $weight->last_score,
                    ],
                ];
            })
            ->all();
    }

    protected function validatePreviousSnapshot(LottusLearningRun $run): ?array
    {
        if (! (bool) config('lottus_fechamento.learning_snapshots.validation.enabled', true)) {
            return null;
        }

        try {
            $validatedSnapshot = $this->promotionService->validateSnapshotForTarget((int) $run->concurso);

            if (! $validatedSnapshot) {
                return [
                    'status' => 'not_found',
                    'target_concurso' => (int) $run->concurso,
                ];
            }

            return [
                'snapshot_id' => $validatedSnapshot->id,
                'target_concurso' => $validatedSnapshot->target_concurso,
                'validation_status' => $validatedSnapshot->validation_status,
                'promoted_strategy' => $validatedSnapshot->promoted_strategy,
                'summary' => ($validatedSnapshot->validation_metrics ?? [])['summary'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error('LOTTUS_ADAPTIVE_LEARNING_PREVIOUS_VALIDATION_FAILED', [
                'run_id' => $run->id,
                'concurso' => $run->concurso,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return [
                'status' => 'failed',
                'target_concurso' => (int) $run->concurso,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ];
        }
    }

    protected function storeSnapshot(LottusLearningRun $run, array $weightsAfter): LottusLearningSnapshot
    {
        $logs = MotorLearningLog::query()
            ->where('engine', 'fechamento')
            ->where('concurso', $run->concurso)
            ->get();

        $structureBias = $this->structureBiasFromLogs($logs);
        $pairBias = $this->pairBiasFromLogs($logs);
        $rawEliteProtection = $this->rawEliteProtectionFromLogs($logs);

        return LottusLearningSnapshot::query()->updateOrCreate(
            [
                'concurso' => $run->concurso,
                'calibration_version' => $run->calibration_version,
            ],
            [
                'target_concurso' => ((int) $run->concurso) + 1,
                'strategy_weights' => $weightsAfter,
                'structure_bias' => $structureBias,
                'pair_bias' => $pairBias,
                'raw_elite_protection' => $rawEliteProtection,
                'metrics_json' => [
                    'learning_log_count' => $logs->count(),
                    'generated_at' => now()->toISOString(),
                    'source_run_id' => $run->id,
                ],
            ]
        );
    }

    protected function structureBiasFromLogs($logs): array
    {
        $byQuantity = [];

        foreach ($logs as $log) {
            $quantity = (int) $log->quantidade_dezenas;
            $base = is_array($log->base_numbers) ? $log->base_numbers : [];
            $hits = is_array($log->hits) ? $log->hits : [];

            if (empty($base)) {
                continue;
            }

            $byQuantity[$quantity] ??= [
                'samples' => 0,
                'sum_total' => 0,
                'odd_total' => 0,
                'hit_total' => 0,
                'max_hits' => 0,
            ];

            $byQuantity[$quantity]['samples']++;
            $byQuantity[$quantity]['sum_total'] += array_sum($base);
            $byQuantity[$quantity]['odd_total'] += count(array_filter($base, fn (int $number): bool => $number % 2 !== 0));
            $byQuantity[$quantity]['hit_total'] += count($hits);
            $byQuantity[$quantity]['max_hits'] = max($byQuantity[$quantity]['max_hits'], count($hits));
        }

        foreach ($byQuantity as $quantity => $data) {
            $samples = max(1, (int) $data['samples']);
            $byQuantity[$quantity] = [
                'samples' => $samples,
                'avg_base_sum' => round($data['sum_total'] / $samples, 4),
                'avg_odd_count' => round($data['odd_total'] / $samples, 4),
                'avg_hits' => round($data['hit_total'] / $samples, 4),
                'max_hits' => (int) $data['max_hits'],
            ];
        }

        return $byQuantity;
    }

    protected function pairBiasFromLogs($logs): array
    {
        $pairs = [];

        foreach ($logs as $log) {
            $hits = is_array($log->hits) ? array_values(array_map('intval', $log->hits)) : [];
            sort($hits);

            for ($i = 0; $i < count($hits); $i++) {
                for ($j = $i + 1; $j < count($hits); $j++) {
                    $key = $hits[$i] . '-' . $hits[$j];
                    $pairs[$key] = ($pairs[$key] ?? 0) + 1;
                }
            }
        }

        arsort($pairs);

        return array_slice($pairs, 0, 80, true);
    }

    protected function rawEliteProtectionFromLogs($logs): array
    {
        $byQuantity = [];

        foreach ($logs as $log) {
            $quantity = (int) $log->quantidade_dezenas;
            $hits = is_array($log->hits) ? count($log->hits) : 0;

            $byQuantity[$quantity] ??= [
                'samples' => 0,
                'raw_13_plus' => 0,
                'raw_14_plus' => 0,
                'raw_15' => 0,
                'max_hits' => 0,
            ];

            $byQuantity[$quantity]['samples']++;
            $byQuantity[$quantity]['max_hits'] = max($byQuantity[$quantity]['max_hits'], $hits);

            if ($hits >= 13) {
                $byQuantity[$quantity]['raw_13_plus']++;
            }

            if ($hits >= 14) {
                $byQuantity[$quantity]['raw_14_plus']++;
            }

            if ($hits >= 15) {
                $byQuantity[$quantity]['raw_15']++;
            }
        }

        return $byQuantity;
    }

    protected function buildMetrics(
        array $weightsBefore,
        array $weightsAfter,
        int $logsCreated,
        int $snapshotId,
        array $learningSummary,
        ?array $validationSummary = null,
        ?array $promotionSummary = null
    ): array {
        $changedStrategies = [];
        $changedWeightKeys = 0;

        foreach ($weightsAfter as $strategy => $after) {
            $beforeWeights = $weightsBefore[$strategy]['weights'] ?? [];
            $afterWeights = $after['weights'] ?? [];
            $changedKeys = [];

            foreach ($afterWeights as $key => $value) {
                $beforeValue = (float) ($beforeWeights[$key] ?? 0.0);
                $afterValue = (float) $value;

                if (abs($afterValue - $beforeValue) >= 0.00000001) {
                    $changedKeys[$key] = [
                        'before' => round($beforeValue, 8),
                        'after' => round($afterValue, 8),
                        'delta' => round($afterValue - $beforeValue, 8),
                    ];
                }
            }

            if (! empty($changedKeys) || ! isset($weightsBefore[$strategy])) {
                $changedStrategies[$strategy] = [
                    'changed_weights' => $changedKeys,
                    'samples_before' => (int) ($weightsBefore[$strategy]['samples'] ?? 0),
                    'samples_after' => (int) ($after['samples'] ?? 0),
                    'last_error' => $after['last_error'] ?? null,
                    'last_score' => $after['last_score'] ?? null,
                ];
                $changedWeightKeys += count($changedKeys);
            }
        }

        return [
            'learning_logs_created' => $logsCreated,
            'snapshot_id' => $snapshotId,
            'learning_summary' => $learningSummary,
            'previous_snapshot_validation' => $validationSummary,
            'snapshot_auto_promotion_policy' => $promotionSummary,
            'changed_strategies_count' => count($changedStrategies),
            'changed_weight_keys_count' => $changedWeightKeys,
            'adjustments_count' => $logsCreated + $changedWeightKeys + count($changedStrategies),
            'changed_strategies' => $changedStrategies,
            'impact_estimate' => [
                'raw_elite_protection' => 'refreshed_by_current_learning_snapshot',
                'strategy_weights' => array_key_exists('fechamento_base_windows', $changedStrategies) ? 'updated' : 'unchanged',
                'structure_bias' => 'available_to_next_cycle_from_learning_logs',
                'pair_bias' => 'available_to_next_cycle_from_learning_logs',
            ],
        ];
    }
}
