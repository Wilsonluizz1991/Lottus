<?php

namespace App\Services\Lottus\Fechamento;

use App\Models\LotofacilConcurso;
use App\Services\Lottus\Analysis\CorrelationAnalysisService;
use App\Services\Lottus\Analysis\CycleAnalysisService;
use App\Services\Lottus\Analysis\DelayAnalysisService;
use App\Services\Lottus\Analysis\FrequencyAnalysisService;
use App\Services\Lottus\Analysis\StructureAnalysisService;
use App\Services\Lottus\Data\HistoricalDataService;

class FechamentoEngine
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
        protected FechamentoBaseVariationService $baseVariationService,
        protected FechamentoCombinationGenerator $combinationGenerator,
        protected FechamentoScoreService $scoreService,
        protected FechamentoCoverageOptimizerService $coverageOptimizerService,
        protected FechamentoReducer $reducer,
        protected FechamentoPersistenceService $persistenceService
    ) {
    }

    public function generate(array $payload): array
    {
        $email = (string) ($payload['email'] ?? '');
        $quantidadeDezenas = (int) ($payload['quantidade_dezenas'] ?? 0);
        $cupom = $payload['cupom'] ?? null;

        /** @var LotofacilConcurso|null $concursoBase */
        $concursoBase = $payload['concurso_base'] ?? null;

        $this->validatePayload($email, $quantidadeDezenas, $concursoBase);

        $historico = $this->historicalDataService->getUntilContest($concursoBase->concurso);

        if ($historico->isEmpty()) {
            throw new \Exception('Histórico insuficiente para gerar o fechamento.');
        }

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

        $dezenasBaseInicial = $this->candidateSelector->select(
            $quantidadeDezenas,
            $frequencyContext,
            $delayContext,
            $correlationContext,
            $structureContext,
            $cycleContext,
            $concursoBase
        );

        if (count($dezenasBaseInicial) !== $quantidadeDezenas) {
            throw new \Exception('Falha ao selecionar as dezenas base inicial do fechamento.');
        }

        $candidateBases = $this->baseCompetitionService->selectTopBases(
            primaryBase: $dezenasBaseInicial,
            quantidadeDezenas: $quantidadeDezenas,
            historico: $historico,
            frequencyContext: $frequencyContext,
            delayContext: $delayContext,
            correlationContext: $correlationContext,
            structureContext: $structureContext,
            cycleContext: $cycleContext,
            concursoBase: $concursoBase,
            patternContext: $patternContext,
            limit: (int) config('lottus_fechamento.base_competition.top_bases', 6)
        );

        if (empty($candidateBases)) {
            $candidateBases = [$dezenasBaseInicial];
        }

        $quantidadeJogos = (int) config("lottus_fechamento.output_games.{$quantidadeDezenas}", 0);

        if ($quantidadeJogos <= 0) {
            throw new \Exception('Quantidade de jogos do fechamento não configurada.');
        }

        $bestPortfolio = null;

        foreach ($candidateBases as $candidateBase) {
            $candidateBase = $this->normalizeNumbers($candidateBase);

            if (count($candidateBase) !== $quantidadeDezenas) {
                continue;
            }

            $portfolio = $this->buildPortfolioForBase(
                dezenasBase: $candidateBase,
                quantidadeDezenas: $quantidadeDezenas,
                quantidadeJogos: $quantidadeJogos,
                frequencyContext: $frequencyContext,
                delayContext: $delayContext,
                correlationContext: $correlationContext,
                structureContext: $structureContext,
                cycleContext: $cycleContext,
                concursoBase: $concursoBase
            );

            if ($portfolio === null) {
                continue;
            }

            if ($bestPortfolio === null || $portfolio['strength'] > $bestPortfolio['strength']) {
                $bestPortfolio = $portfolio;
            }
        }

        if ($bestPortfolio === null || count($bestPortfolio['jogos']) < $quantidadeJogos) {
            throw new \Exception('O fechamento não conseguiu selecionar a quantidade final de jogos.');
        }

        logger()->info('FECHAMENTO_MULTI_BASE_SELECTED', [
            'concurso' => $concursoBase->concurso,
            'quantidade_dezenas' => $quantidadeDezenas,
            'bases_testadas' => count($candidateBases),
            'strength' => $bestPortfolio['strength'],
            'dezenas_base' => $bestPortfolio['dezenas_base'],
            'portfolio_profile' => $bestPortfolio['profile'],
        ]);

        return $this->persistenceService->store(
            email: $email,
            concursoBase: $concursoBase,
            quantidadeDezenas: $quantidadeDezenas,
            dezenasBase: $bestPortfolio['dezenas_base'],
            jogos: $bestPortfolio['jogos'],
            cupom: $cupom
        );
    }

    protected function buildPortfolioForBase(
        array $dezenasBase,
        int $quantidadeDezenas,
        int $quantidadeJogos,
        array $frequencyContext,
        array $delayContext,
        array $correlationContext,
        array $structureContext,
        array $cycleContext,
        LotofacilConcurso $concursoBase
    ): ?array {
        $bases = [$dezenasBase];

        if ((bool) config('lottus_fechamento.base_variations.enabled', false)) {
            $numberScores = $this->baseCompetitionService->getLastNumberScores();

            if (empty($numberScores)) {
                $numberScores = $frequencyContext['scores'] ?? [];
            }

            $bases = $this->baseVariationService->generate(
                base: $dezenasBase,
                numberScores: $numberScores,
                quantidadeDezenas: $quantidadeDezenas,
                maxVariations: match ($quantidadeDezenas) {
                    16 => 1,
                    17 => 2,
                    18 => 3,
                    19 => 4,
                    20 => 5,
                    default => 3,
                }
            );
        }

        $combinations = [];

        foreach ($bases as $baseVariation) {
            $baseVariation = $this->normalizeNumbers($baseVariation);

            if (count($baseVariation) !== $quantidadeDezenas) {
                continue;
            }

            $generated = $this->combinationGenerator->generate(
                $baseVariation,
                $quantidadeDezenas
            );

            if (! empty($generated)) {
                $combinations = array_merge($combinations, $generated);
            }
        }

        $combinations = $this->uniqueCombinations($combinations);

        if (empty($combinations)) {
            return null;
        }

        $scoredCombinations = $this->scoreService->score(
            $combinations,
            $frequencyContext,
            $delayContext,
            $correlationContext,
            $structureContext,
            $cycleContext,
            $concursoBase
        );

        if (empty($scoredCombinations)) {
            return null;
        }

        $selectedGames = $this->coverageOptimizerService->optimize(
            $scoredCombinations,
            $quantidadeJogos,
            $dezenasBase
        );

        if (count($selectedGames) < $quantidadeJogos) {
            $selectedGames = $this->reducer->reduce(
                $scoredCombinations,
                $quantidadeJogos,
                $dezenasBase
            );
        }

        if (count($selectedGames) < $quantidadeJogos) {
            return null;
        }

        $profile = $this->portfolioProfile($selectedGames, $scoredCombinations);

        return [
            'dezenas_base' => $dezenasBase,
            'jogos' => $selectedGames,
            'strength' => $this->portfolioStrength($profile),
            'profile' => $profile,
        ];
    }

    protected function portfolioProfile(array $selectedGames, array $scoredCombinations): array
    {
        $selectedScores = array_map(
            fn (array $game): float => (float) ($game['score'] ?? 0.0),
            $selectedGames
        );

        rsort($selectedScores);

        $rawScores = array_map(
            fn (array $game): float => (float) ($game['score'] ?? 0.0),
            $scoredCombinations
        );

        rsort($rawScores);

        $topRaw = array_slice($rawScores, 0, max(1, min(20, count($rawScores))));
        $topSelected = array_slice($selectedScores, 0, max(1, min(20, count($selectedScores))));

        return [
            'raw_best' => (float) ($rawScores[0] ?? 0.0),
            'selected_best' => (float) ($selectedScores[0] ?? 0.0),
            'raw_top20_avg' => $this->average($topRaw),
            'selected_top20_avg' => $this->average($topSelected),
            'selected_avg' => $this->average($selectedScores),
            'selected_floor' => (float) (min($selectedScores) ?: 0.0),
            'selected_count' => count($selectedGames),
        ];
    }

    protected function portfolioStrength(array $profile): float
    {
        return
            ((float) ($profile['selected_best'] ?? 0.0) * 0.34) +
            ((float) ($profile['selected_top20_avg'] ?? 0.0) * 0.30) +
            ((float) ($profile['raw_best'] ?? 0.0) * 0.18) +
            ((float) ($profile['raw_top20_avg'] ?? 0.0) * 0.12) +
            ((float) ($profile['selected_avg'] ?? 0.0) * 0.06);
    }

    protected function uniqueCombinations(array $combinations): array
    {
        $unique = [];

        foreach ($combinations as $game) {
            $game = $this->normalizeNumbers($game);

            if (count($game) !== 15) {
                continue;
            }

            $unique[implode('-', $game)] = $game;
        }

        return array_values($unique);
    }

    protected function normalizeNumbers(array $numbers): array
    {
        $numbers = array_values(array_unique(array_map('intval', $numbers)));
        sort($numbers);

        return $numbers;
    }

    protected function average(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    protected function validatePayload(
        string $email,
        int $quantidadeDezenas,
        ?LotofacilConcurso $concursoBase
    ): void {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('E-mail inválido para geração do fechamento.');
        }

        $min = (int) config('lottus_fechamento.min_dezenas', 16);
        $max = (int) config('lottus_fechamento.max_dezenas', 20);

        if ($quantidadeDezenas < $min || $quantidadeDezenas > $max) {
            throw new \InvalidArgumentException("A quantidade de dezenas deve estar entre {$min} e {$max}.");
        }

        if (! $concursoBase) {
            throw new \InvalidArgumentException('Concurso base não informado para o fechamento.');
        }
    }
}
