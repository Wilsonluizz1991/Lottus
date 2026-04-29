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
        protected FechamentoCandidateSelector $candidateSelector,
        protected FechamentoCombinationGenerator $combinationGenerator,
        protected FechamentoScoreService $scoreService,
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

        $dezenasBase = $this->candidateSelector->select(
            $quantidadeDezenas,
            $frequencyContext,
            $delayContext,
            $correlationContext,
            $structureContext,
            $cycleContext,
            $concursoBase
        );

        if (count($dezenasBase) !== $quantidadeDezenas) {
            throw new \Exception('Falha ao selecionar as dezenas base do fechamento.');
        }

        $combinations = $this->combinationGenerator->generate(
            $dezenasBase,
            $quantidadeDezenas
        );

        if (empty($combinations)) {
            throw new \Exception('Nenhuma combinação foi gerada para o fechamento.');
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

        $quantidadeJogos = (int) config("lottus_fechamento.output_games.{$quantidadeDezenas}", 0);

        if ($quantidadeJogos <= 0) {
            throw new \Exception('Quantidade de jogos do fechamento não configurada.');
        }

        $selectedGames = $this->reducer->reduce(
            $scoredCombinations,
            $quantidadeJogos,
            $dezenasBase
        );

        if (count($selectedGames) < $quantidadeJogos) {
            throw new \Exception('O fechamento não conseguiu selecionar a quantidade final de jogos.');
        }

        return $this->persistenceService->store(
            email: $email,
            concursoBase: $concursoBase,
            quantidadeDezenas: $quantidadeDezenas,
            dezenasBase: $dezenasBase,
            jogos: $selectedGames,
            cupom: $cupom
        );
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