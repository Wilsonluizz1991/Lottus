<?php

namespace App\Services\Lottus\Engine;

use App\Models\LotofacilConcurso;
use App\Services\Lottus\Analysis\CorrelationAnalysisService;
use App\Services\Lottus\Analysis\CycleAnalysisService;
use App\Services\Lottus\Analysis\DelayAnalysisService;
use App\Services\Lottus\Analysis\FrequencyAnalysisService;
use App\Services\Lottus\Analysis\StructureAnalysisService;
use App\Services\Lottus\Data\HistoricalDataService;
use App\Services\Lottus\Generation\CandidateGeneratorService;
use App\Services\Lottus\Generation\CoreClusterPreservationService;
use App\Services\Lottus\Generation\GameScoringService;
use App\Services\Lottus\Generation\HighCeilingCandidateGeneratorService;
use App\Services\Lottus\Generation\PortfolioOptimizerService;
use App\Services\Lottus\Persistence\GeneratedBetPersistenceService;

class LotofacilEngine
{
    public function __construct(
        protected HistoricalDataService $historicalDataService,
        protected FrequencyAnalysisService $frequencyAnalysisService,
        protected DelayAnalysisService $delayAnalysisService,
        protected CorrelationAnalysisService $correlationAnalysisService,
        protected StructureAnalysisService $structureAnalysisService,
        protected CycleAnalysisService $cycleAnalysisService,
        protected CandidateGeneratorService $candidateGeneratorService,
        protected HighCeilingCandidateGeneratorService $highCeilingCandidateGeneratorService,
        protected GameScoringService $gameScoringService,
        protected CoreClusterPreservationService $coreClusterPreservationService,
        protected PortfolioOptimizerService $portfolioOptimizerService,
        protected GeneratedBetPersistenceService $generatedBetPersistenceService
    ) {
    }

    public function generate(array $payload): array
    {
        /** @var LotofacilConcurso $concursoBase */
        $concursoBase = $payload['concurso_base'];
        $quantidade = (int) $payload['quantidade'];
        $email = $payload['email'];

        $historico = $this->historicalDataService->getUntilContest($concursoBase->concurso);

        if ($historico->isEmpty()) {
            throw new \Exception('Histórico insuficiente para geração dos jogos.');
        }

        $frequencyContext = $this->frequencyAnalysisService->analyze($historico);
        $delayContext = $this->delayAnalysisService->analyze($historico);
        $correlationContext = $this->correlationAnalysisService->analyze($historico);
        $structureContext = $this->structureAnalysisService->analyze($historico);
        $cycleContext = $this->cycleAnalysisService->analyze($historico);

        $weights = $this->resolveWeights($cycleContext, $concursoBase);

        $candidateGames = $this->candidateGeneratorService->generate(
            $quantidade,
            $frequencyContext,
            $delayContext,
            $correlationContext,
            $structureContext,
            $weights
        );

        $candidatePayloads = $this->normalizeCandidatePayloads(
            $candidateGames,
            'baseline_explosive',
            'explosive',
            $cycleContext['faltantes'] ?? []
        );

        $eliteCandidatePayloads = $this->highCeilingCandidateGeneratorService->generate(
            $quantidade,
            $frequencyContext,
            $delayContext,
            $correlationContext,
            $structureContext,
            $weights,
            $historico
        );

        $candidates = $this->mergeCandidatePayloads($candidatePayloads, $eliteCandidatePayloads);

        if (empty($candidates)) {
            throw new \Exception('Nenhum jogo candidato foi gerado pelo motor.');
        }

        $rankedGames = $this->gameScoringService->rank(
            $candidates,
            $frequencyContext,
            $delayContext,
            $correlationContext,
            $structureContext,
            $concursoBase,
            $historico
        );

        $rankedGames = $this->coreClusterPreservationService->preserve($rankedGames);

        if (empty($rankedGames)) {
            throw new \Exception('Nenhum jogo ranqueado foi produzido pelo motor.');
        }

        $selectedGames = $this->portfolioOptimizerService->optimize($rankedGames, $quantidade);

        if (count($selectedGames) < $quantidade) {
            throw new \Exception('O motor não conseguiu selecionar a quantidade solicitada de jogos.');
        }

        return $this->generatedBetPersistenceService->store(
            $email,
            $concursoBase,
            $selectedGames
        );
    }

    protected function resolveWeights(array $cycleContext, LotofacilConcurso $concursoBase): array
    {
        $weights = $this->pickChampionWeights();

        if ((bool) config('lottus.micro_variation.enabled', false)) {
            $range = (float) config('lottus.micro_variation.range', 0.02);

            $weights['frequency'] = $this->vary($weights['frequency'], $range);
            $weights['delay'] = $this->vary($weights['delay'], $range);
            $weights['correlation'] = $this->vary($weights['correlation'], $range);
            $weights['cycle'] = $this->vary($weights['cycle'], $range);

            $sum = array_sum($weights);

            if ($sum > 0) {
                foreach ($weights as $key => $value) {
                    $weights[$key] = round($value / $sum, 4);
                }
            }
        }

        return array_merge($weights, [
            'faltantes' => $cycleContext['faltantes'] ?? [],
            'last_draw_numbers' => $this->extractNumbers($concursoBase),
            'scores' => $cycleContext['scores'] ?? [],
            'cycle_scores' => $cycleContext['scores'] ?? [],
        ]);
    }

    protected function pickChampionWeights(): array
    {
        $champions = config('lottus.champion_weights', []);

        if (! empty($champions)) {
            return $champions[array_rand($champions)];
        }

        return [
            'frequency' => (float) config('lottus.weights.frequency', 0.20),
            'delay' => (float) config('lottus.weights.delay', 0.30),
            'correlation' => (float) config('lottus.weights.correlation', 0.20),
            'cycle' => (float) config('lottus.weights.cycle', 0.30),
        ];
    }

    protected function vary(float $value, float $range): float
    {
        $delta = mt_rand((int) (-$range * 10000), (int) ($range * 10000)) / 10000;

        return max(0.01, $value + $delta);
    }

    protected function extractNumbers(LotofacilConcurso $concursoBase): array
    {
        $numbers = [];

        for ($i = 1; $i <= 15; $i++) {
            $numbers[] = (int) $concursoBase->{'bola' . $i};
        }

        sort($numbers);

        return $numbers;
    }

    protected function normalizeCandidatePayloads(
        array $candidateGames,
        string $strategy,
        string $profile,
        array $cycleMissing
    ): array {
        $payloads = [];

        foreach ($candidateGames as $candidate) {
            $game = $candidate['dezenas'] ?? $candidate;
            $game = array_values(array_unique(array_map('intval', $game)));
            sort($game);

            if (count($game) !== 15) {
                continue;
            }

            $payloads[] = [
                'dezenas' => $game,
                'profile' => $candidate['profile'] ?? $profile,
                'strategy' => $candidate['strategy'] ?? $strategy,
                'cycle_missing' => $candidate['cycle_missing'] ?? $cycleMissing,
            ];
        }

        return $payloads;
    }

    protected function mergeCandidatePayloads(array ...$groups): array
    {
        $merged = [];
        $seen = [];

        foreach ($groups as $group) {
            foreach ($group as $candidate) {
                $game = $candidate['dezenas'] ?? [];
                sort($game);
                $key = implode('-', $game);

                if (count($game) !== 15 || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $candidate['dezenas'] = $game;
                $merged[] = $candidate;
            }
        }

        return $merged;
    }
}
