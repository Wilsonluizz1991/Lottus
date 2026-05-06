<?php

namespace App\Services\Lottus\Learning\Drivers;

use App\Models\LotofacilConcurso;
use App\Models\MotorLearningLog;
use App\Services\Lottus\Analysis\CorrelationAnalysisService;
use App\Services\Lottus\Analysis\CycleAnalysisService;
use App\Services\Lottus\Analysis\DelayAnalysisService;
use App\Services\Lottus\Analysis\FrequencyAnalysisService;
use App\Services\Lottus\Analysis\StructureAnalysisService;
use App\Services\Lottus\Data\HistoricalDataService;
use App\Services\Lottus\Fechamento\FechamentoBaseCompetitionService;
use App\Services\Lottus\Fechamento\FechamentoCandidateSelector;
use App\Services\Lottus\Fechamento\FechamentoPatternPredictionService;
use App\Services\Lottus\Learning\Contracts\LearningStrategyInterface;
use App\Services\Lottus\Learning\MotorLearningWeightService;

class SimpleReinforcementLearningDriver implements LearningStrategyInterface
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
        protected MotorLearningWeightService $weightService
    ) {
    }

    public function engine(): string
    {
        return 'fechamento';
    }

    public function strategy(): string
    {
        return 'fechamento_base_windows';
    }

    public function learn(
        LotofacilConcurso $concursoAtual
    ): array {
        $summary = [
            'quantities_processed' => 0,
            'learning_logs_created' => 0,
            'weight_updates' => 0,
        ];

        $concursoAnterior = LotofacilConcurso::query()
            ->where('concurso', '<', $concursoAtual->concurso)
            ->orderByDesc('concurso')
            ->first();

        if (! $concursoAnterior) {
            return $summary + ['skipped_reason' => 'previous_contest_not_found'];
        }

        $historico = $this->historicalDataService->getUntilContest($concursoAnterior->concurso);

        if ($historico->count() < 160) {
            return $summary + ['skipped_reason' => 'insufficient_history'];
        }

        $resultadoNumbers = $this->extractNumbers($concursoAtual);

        foreach ([16, 17, 18, 19, 20] as $quantidadeDezenas) {
            $quantitySummary = $this->learnForQuantity(
                quantidadeDezenas: $quantidadeDezenas,
                concursoBase: $concursoAnterior,
                concursoAtual: $concursoAtual,
                historico: $historico,
                resultadoNumbers: $resultadoNumbers
            );

            $summary['quantities_processed'] += (int) ($quantitySummary['processed'] ?? 0);
            $summary['learning_logs_created'] += (int) ($quantitySummary['logs_created'] ?? 0);
            $summary['weight_updates'] += (int) ($quantitySummary['weight_updated'] ?? 0);
        }

        return $summary;
    }

    protected function learnForQuantity(
        int $quantidadeDezenas,
        LotofacilConcurso $concursoBase,
        LotofacilConcurso $concursoAtual,
        $historico,
        array $resultadoNumbers
    ): array {
        $summary = [
            'processed' => 0,
            'logs_created' => 0,
            'weight_updated' => 0,
        ];

        $frequencyContext = $this->frequencyAnalysisService->analyze($historico);
        $delayContext = $this->delayAnalysisService->analyze($historico);
        $correlationContext = $this->correlationAnalysisService->analyze($historico);
        $structureContext = $this->structureAnalysisService->analyze($historico);
        $cycleContext = $this->cycleAnalysisService->analyze($historico);

        $patternContext = $this->patternPredictionService->predict(
            historico: $historico,
            frequencyContext: $frequencyContext,
            delayContext: $delayContext,
            correlationContext: $correlationContext,
            structureContext: $structureContext,
            cycleContext: $cycleContext,
            concursoBase: $concursoBase
        );

        $primaryBase = $this->candidateSelector->select(
            $quantidadeDezenas,
            $frequencyContext,
            $delayContext,
            $correlationContext,
            $structureContext,
            $cycleContext,
            $concursoBase
        );

        if (count($primaryBase) !== $quantidadeDezenas) {
            return $summary + ['skipped_reason' => 'invalid_primary_base'];
        }

        $candidateBases = $this->baseCompetitionService->selectTopBases(
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
            limit: 8
        );

        if (empty($candidateBases)) {
            return $summary + ['skipped_reason' => 'empty_candidate_bases'];
        }

        $performanceByWindow = [
            'window_40' => 0.0,
            'window_80' => 0.0,
            'window_160' => 0.0,
            'window_total' => 0.0,
        ];

        $bestScore = 0.0;
        $bestError = 15.0;

        foreach ($candidateBases as $base) {
            $base = $this->normalizeNumbers($base);
            $hits = array_values(array_intersect($base, $resultadoNumbers));
            sort($hits);

            $hitCount = count($hits);
            $predictionError = max(0, 15 - $hitCount);
            $proximityScore = $hitCount / 15;

            $windowContribution = $this->estimateWindowContribution(
                base: $base,
                resultadoNumbers: $resultadoNumbers,
                historico: $historico
            );

            foreach ($performanceByWindow as $key => $value) {
                $performanceByWindow[$key] += (float) ($windowContribution[$key] ?? 0.0);
            }

            if ($proximityScore > $bestScore) {
                $bestScore = $proximityScore;
                $bestError = $predictionError;
            }

            MotorLearningLog::create([
                'engine' => $this->engine(),
                'strategy' => $this->strategy(),
                'concurso' => $concursoAtual->concurso,
                'quantidade_dezenas' => $quantidadeDezenas,
                'base_numbers' => $base,
                'resultado_numbers' => $resultadoNumbers,
                'hits' => $hits,
                'misses' => array_values(array_diff($resultadoNumbers, $base)),
                'prediction_error' => $predictionError,
                'proximity_score' => $proximityScore,
                'metrics' => [
                    'hit_count' => $hitCount,
                    'concurso_base' => $concursoBase->concurso,
                    'window_contribution' => $windowContribution,
                    'pattern_regime' => $patternContext['regime'] ?? null,
                    'pattern_confidence' => $patternContext['confidence'] ?? null,
                ],
                'processed_at' => now(),
            ]);

            $summary['logs_created']++;
        }

        $performanceByWindow = $this->normalizeWeights($performanceByWindow);

        $currentWeights = $this->weightService->getWeights(
            strategy: $this->strategy(),
            fallback: [
                'window_40' => 0.22,
                'window_80' => 0.28,
                'window_160' => 0.30,
                'window_total' => 0.20,
            ]
        );

        $this->weightService->updateWeights(
            strategy: $this->strategy(),
            currentWeights: $currentWeights,
            performanceByFeature: $performanceByWindow,
            concurso: $concursoAtual->concurso,
            error: $bestError,
            score: $bestScore
        );

        $summary['processed'] = 1;
        $summary['weight_updated'] = 1;

        return $summary;
    }

    protected function estimateWindowContribution(
        array $base,
        array $resultadoNumbers,
        $historico
    ): array {
        $windows = [
            'window_40' => 40,
            'window_80' => 80,
            'window_160' => 160,
            'window_total' => null,
        ];

        $scores = [];

        foreach ($windows as $key => $size) {
            $draws = $historico
                ->sortBy('concurso')
                ->when($size, fn ($collection) => $collection->take(-$size))
                ->map(fn ($concurso) => $this->extractNumbers($concurso))
                ->filter(fn (array $numbers) => count($numbers) === 15)
                ->values();

            if ($draws->isEmpty()) {
                $scores[$key] = 0.0;
                continue;
            }

            $frequency = [];

            foreach ($draws as $draw) {
                foreach ($draw as $number) {
                    $frequency[$number] = ($frequency[$number] ?? 0) + 1;
                }
            }

            $windowRank = collect($frequency)
                ->sortDesc()
                ->keys()
                ->map(fn ($number) => (int) $number)
                ->take(count($base))
                ->values()
                ->all();

            $windowHits = count(array_intersect($windowRank, $resultadoNumbers));
            $baseHits = count(array_intersect($base, $resultadoNumbers));

            $scores[$key] = max(0.0, min(1.0, (($windowHits * 0.55) + ($baseHits * 0.45)) / 15));
        }

        return $scores;
    }

    protected function extractNumbers($concurso): array
    {
        if (is_array($concurso)) {
            foreach (['dezenas', 'numbers'] as $key) {
                if (! empty($concurso[$key]) && is_array($concurso[$key])) {
                    return $this->normalizeNumbers($concurso[$key]);
                }
            }
        }

        $numbers = [];

        for ($i = 1; $i <= 15; $i++) {
            $field = 'bola' . $i;

            if (isset($concurso->{$field})) {
                $numbers[] = (int) $concurso->{$field};
            }
        }

        sort($numbers);

        return $numbers;
    }

    protected function normalizeNumbers(array $numbers): array
    {
        $numbers = array_values(array_unique(array_map('intval', $numbers)));
        $numbers = array_values(array_filter($numbers, fn (int $number) => $number >= 1 && $number <= 25));
        sort($numbers);

        return $numbers;
    }

    protected function normalizeWeights(array $weights): array
    {
        $clean = [];

        foreach ($weights as $key => $value) {
            $clean[$key] = max(0.0, (float) $value);
        }

        $sum = array_sum($clean);

        if ($sum <= 0) {
            $count = max(1, count($clean));

            foreach ($clean as $key => $value) {
                $clean[$key] = round(1 / $count, 8);
            }

            return $clean;
        }

        foreach ($clean as $key => $value) {
            $clean[$key] = round($value / $sum, 8);
        }

        return $clean;
    }
}
