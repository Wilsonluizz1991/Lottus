<?php

namespace App\Services\Lottus\MainLearning;

use App\Services\Lottus\Backtest\BacktestService;
use App\Services\Lottus\Data\HistoricalDataService;

class LottusMainLearningEvaluationService
{
    public function __construct(
        protected BacktestService $backtestService,
        protected HistoricalDataService $historicalDataService,
        protected LottusMainTrendDetectionService $trendDetectionService,
        protected LottusMainBehaviorAnalysisService $behaviorAnalysisService,
        protected LottusMainPortfolioCalibrationService $portfolioCalibrationService
    ) {
    }

    public function compareRange(int $inicio, int $fim, int $jogos, ?array $fixedPayload = null): array
    {
        $baselineSummary = $this->baselineSummary($inicio, $fim, $jogos);
        $baselineMetrics = $this->behaviorAnalysisService->metricsFromBacktest($baselineSummary);

        $learnedSummary = $fixedPayload === null
            ? $this->walkForwardLearnedSummary($inicio, $fim, $jogos, null)
            : $this->learnedSummary($inicio, $fim, $jogos, $fixedPayload);
        $learnedMetrics = $this->behaviorAnalysisService->metricsFromBacktest($learnedSummary);
        $delta = $this->behaviorAnalysisService->delta($baselineMetrics, $learnedMetrics);

        return [
            'baseline_summary' => $baselineSummary,
            'learned_summary' => $learnedSummary,
            'baseline_metrics' => $baselineMetrics,
            'learned_metrics' => $learnedMetrics,
            'delta' => $delta,
        ];
    }

    public function compareFixedPayload(
        int $inicio,
        int $fim,
        int $jogos,
        array $payload,
        ?array $baselineSummary = null
    ): array {
        $baselineSummary ??= $this->baselineSummary($inicio, $fim, $jogos);
        $baselineMetrics = $this->behaviorAnalysisService->metricsFromBacktest($baselineSummary);
        $learnedSummary = $this->learnedSummary($inicio, $fim, $jogos, $payload);
        $learnedMetrics = $this->behaviorAnalysisService->metricsFromBacktest($learnedSummary);
        $delta = $this->behaviorAnalysisService->delta($baselineMetrics, $learnedMetrics);

        return [
            'baseline_summary' => $baselineSummary,
            'learned_summary' => $learnedSummary,
            'baseline_metrics' => $baselineMetrics,
            'learned_metrics' => $learnedMetrics,
            'delta' => $delta,
        ];
    }

    public function comparePortfolioCalibration(int $inicio, int $fim, int $jogos): array
    {
        $baselineSummary = $this->baselineSummary($inicio, $fim, $jogos);
        $portfolioCalibration = $this->portfolioCalibrationService->calibrate($baselineSummary);
        $payload = [
            'version' => 1,
            'generated_at' => now()->toISOString(),
            'sample_size' => (int) ($baselineSummary['concursos_testados'] ?? 0),
            'number_bias' => [],
            'pair_bias' => [],
            'structure_bias' => [],
            'strategy_weights' => [],
            'score_adjustments' => [],
            'raw_elite_protection' => ['enabled' => true],
            'portfolio_rules' => $portfolioCalibration['portfolio_rules'] ?? [],
            'portfolio_calibration' => $portfolioCalibration['metrics'] ?? [],
            'trend_metrics' => [
                'variant' => 'portfolio_only',
                'reason' => 'portfolio_rank_loss_calibration_report',
            ],
            '_variant' => 'portfolio_only',
        ];

        return $this->compareFixedPayload($inicio, $fim, $jogos, $payload, $baselineSummary);
    }

    public function baselineMetrics(int $inicio, int $fim, int $jogos): array
    {
        return $this->behaviorAnalysisService->metricsFromBacktest(
            $this->baselineSummary($inicio, $fim, $jogos)
        );
    }

    public function baselineSummary(int $inicio, int $fim, int $jogos): array
    {
        return $this->backtestService->run(
            $inicio,
            $fim,
            $jogos,
            null,
            null,
            true,
            null,
            true
        );
    }

    public function compareShortPackages(int $inicio, int $fim, ?array $fixedPayload = null): array
    {
        $results = [];

        foreach ((array) config('lottus_main_learning.validation_quantities', [1, 2, 3, 5, 10]) as $jogos) {
            $jogos = (int) $jogos;

            if ($jogos < 1 || $jogos > 10) {
                continue;
            }

            $results[$jogos] = $this->compareRange($inicio, $fim, $jogos, $fixedPayload);
        }

        return $results;
    }

    protected function walkForwardLearnedSummary(int $inicio, int $fim, int $jogos, ?array $fixedPayload): array
    {
        $combined = null;

        for ($base = $inicio; $base < $fim; $base++) {
            $target = $base + 1;
            $payload = $fixedPayload;

            if ($payload === null) {
                $historico = $this->historicalDataService->getUntilContest($base);
                $payload = $this->trendDetectionService->detect($historico);
                $calibrationStart = max(1, $base - min(12, (int) config('lottus_main_learning.validation_lookback', 12)));
                $baselineSummary = $this->baselineSummary($calibrationStart, $base, 10);
                $portfolioCalibration = $this->portfolioCalibrationService->calibrate($baselineSummary);
                $payload['portfolio_rules'] = array_replace_recursive(
                    $payload['portfolio_rules'] ?? [],
                    $portfolioCalibration['portfolio_rules'] ?? []
                );
                $payload['portfolio_calibration'] = $portfolioCalibration['metrics'] ?? [];
            }

            $summary = $this->backtestService->run(
                $base,
                $target,
                $jogos,
                null,
                null,
                true,
                $payload,
                false
            );

            $combined = $combined === null ? $summary : $this->mergeSummaries($combined, $summary);
        }

        return $combined ?? $this->emptySummary($inicio, $fim, $jogos);
    }

    protected function learnedSummary(int $inicio, int $fim, int $jogos, array $payload): array
    {
        return $this->backtestService->run(
            $inicio,
            $fim,
            $jogos,
            null,
            null,
            true,
            $payload,
            false
        );
    }

    protected function mergeSummaries(array $left, array $right): array
    {
        $left['concursos_testados'] = (int) ($left['concursos_testados'] ?? 0) + (int) ($right['concursos_testados'] ?? 0);
        $left['jogos_gerados'] = (int) ($left['jogos_gerados'] ?? 0) + (int) ($right['jogos_gerados'] ?? 0);

        foreach ([11, 12, 13, 14, 15] as $faixa) {
            $left['faixas'][$faixa] = (int) ($left['faixas'][$faixa] ?? 0) + (int) ($right['faixas'][$faixa] ?? 0);
            $left['raw_melhor_faixas'][$faixa] = (int) ($left['raw_melhor_faixas'][$faixa] ?? 0) + (int) ($right['raw_melhor_faixas'][$faixa] ?? 0);
        }

        foreach ([
            'raw_14_15_total',
            'raw_14_15_preservados',
            'raw_14_15_loss',
            'near_15_raw_candidates',
            'raw_15_candidates',
            'selected_14_plus_contests',
            'selected_15_contests',
        ] as $key) {
            $left[$key] = (int) ($left[$key] ?? 0) + (int) ($right[$key] ?? 0);
        }

        foreach (($right['strategy_stats'] ?? []) as $strategy => $stats) {
            if (! isset($left['strategy_stats'][$strategy])) {
                $left['strategy_stats'][$strategy] = [
                    'candidates' => 0,
                    'best_hits' => 0,
                    'raw_13' => 0,
                    'raw_14' => 0,
                    'raw_15' => 0,
                ];
            }

            $left['strategy_stats'][$strategy]['candidates'] += (int) ($stats['candidates'] ?? 0);
            $left['strategy_stats'][$strategy]['best_hits'] = max(
                (int) ($left['strategy_stats'][$strategy]['best_hits'] ?? 0),
                (int) ($stats['best_hits'] ?? 0)
            );
            $left['strategy_stats'][$strategy]['raw_13'] += (int) ($stats['raw_13'] ?? 0);
            $left['strategy_stats'][$strategy]['raw_14'] += (int) ($stats['raw_14'] ?? 0);
            $left['strategy_stats'][$strategy]['raw_15'] += (int) ($stats['raw_15'] ?? 0);
        }

        if (($right['melhor_resultado']['acertos'] ?? 0) > ($left['melhor_resultado']['acertos'] ?? 0)) {
            $left['melhor_resultado'] = $right['melhor_resultado'];
        }

        $left['acertos_por_concurso'] = array_merge($left['acertos_por_concurso'] ?? [], $right['acertos_por_concurso'] ?? []);
        $left['diagnostico'] = array_merge($left['diagnostico'] ?? [], $right['diagnostico'] ?? []);
        $left['taxas'] = $this->calculateRates($left['faixas'], max(1, (int) ($left['jogos_gerados'] ?? 1)));

        return $left;
    }

    protected function emptySummary(int $inicio, int $fim, int $jogos): array
    {
        return [
            'inicio_concurso' => $inicio,
            'fim_concurso' => $fim,
            'quantidade_jogos_por_concurso' => $jogos,
            'concursos_testados' => 0,
            'jogos_gerados' => 0,
            'faixas' => [11 => 0, 12 => 0, 13 => 0, 14 => 0, 15 => 0],
            'raw_melhor_faixas' => [11 => 0, 12 => 0, 13 => 0, 14 => 0, 15 => 0],
            'raw_14_15_total' => 0,
            'raw_14_15_preservados' => 0,
            'raw_14_15_loss' => 0,
            'near_15_raw_candidates' => 0,
            'raw_15_candidates' => 0,
            'selected_14_plus_contests' => 0,
            'selected_15_contests' => 0,
            'strategy_stats' => [],
            'acertos_por_concurso' => [],
            'diagnostico' => [],
            'melhor_resultado' => ['concurso' => null, 'acertos' => 0, 'jogo' => [], 'resultado' => []],
            'taxas' => [11 => 0, 12 => 0, 13 => 0, 14 => 0, 15 => 0, '13_plus' => 0, '14_plus' => 0],
        ];
    }

    protected function calculateRates(array $faixas, int $totalJogos): array
    {
        return [
            11 => round(((int) ($faixas[11] ?? 0) / $totalJogos) * 100, 4),
            12 => round(((int) ($faixas[12] ?? 0) / $totalJogos) * 100, 4),
            13 => round(((int) ($faixas[13] ?? 0) / $totalJogos) * 100, 4),
            14 => round(((int) ($faixas[14] ?? 0) / $totalJogos) * 100, 4),
            15 => round(((int) ($faixas[15] ?? 0) / $totalJogos) * 100, 4),
            '13_plus' => round((((int) ($faixas[13] ?? 0) + (int) ($faixas[14] ?? 0) + (int) ($faixas[15] ?? 0)) / $totalJogos) * 100, 4),
            '14_plus' => round((((int) ($faixas[14] ?? 0) + (int) ($faixas[15] ?? 0)) / $totalJogos) * 100, 4),
        ];
    }
}
