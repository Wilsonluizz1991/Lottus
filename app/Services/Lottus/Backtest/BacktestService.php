<?php

namespace App\Services\Lottus\Backtest;

use App\Models\LotofacilConcurso;
use App\Services\Lottus\Analysis\CorrelationAnalysisService;
use App\Services\Lottus\Analysis\CycleAnalysisService;
use App\Services\Lottus\Analysis\DelayAnalysisService;
use App\Services\Lottus\Analysis\FrequencyAnalysisService;
use App\Services\Lottus\Analysis\StructureAnalysisService;
use App\Services\Lottus\Data\HistoricalDataService;
use App\Services\Lottus\Generation\CandidateGeneratorService;
use App\Services\Lottus\Generation\GameScoringService;
use App\Services\Lottus\Generation\PortfolioOptimizerService;

class BacktestService
{
    public function __construct(
        protected HistoricalDataService $historicalDataService,
        protected FrequencyAnalysisService $frequencyAnalysisService,
        protected DelayAnalysisService $delayAnalysisService,
        protected CorrelationAnalysisService $correlationAnalysisService,
        protected StructureAnalysisService $structureAnalysisService,
        protected CycleAnalysisService $cycleAnalysisService,
        protected CandidateGeneratorService $candidateGeneratorService,
        protected GameScoringService $gameScoringService,
        protected PortfolioOptimizerService $portfolioOptimizerService
    ) {
    }

    public function run(int $inicioConcurso, int $fimConcurso, int $quantidadeJogos = 1): array
    {
        if ($inicioConcurso >= $fimConcurso) {
            throw new \InvalidArgumentException('O concurso inicial deve ser menor que o concurso final.');
        }

        if ($quantidadeJogos < 1) {
            throw new \InvalidArgumentException('A quantidade de jogos deve ser no mínimo 1.');
        }

        $concursos = LotofacilConcurso::query()
            ->whereBetween('concurso', [$inicioConcurso, $fimConcurso])
            ->orderBy('concurso')
            ->get();

        if ($concursos->count() < 2) {
            throw new \Exception('Intervalo insuficiente para backtest.');
        }

        $resumo = [
            'inicio_concurso' => $inicioConcurso,
            'fim_concurso' => $fimConcurso,
            'quantidade_jogos_por_concurso' => $quantidadeJogos,
            'quantidade_candidatos_por_concurso' => (int) config('lottus.generator.target_candidates', 200),
            'concursos_testados' => 0,
            'jogos_gerados' => 0,
            'faixas' => [
                11 => 0,
                12 => 0,
                13 => 0,
                14 => 0,
                15 => 0,
            ],
            'acertos_por_concurso' => [],
            'melhor_resultado' => [
                'concurso' => null,
                'acertos' => 0,
                'jogo' => [],
                'resultado' => [],
            ],
            'diagnostico' => [],
        ];

        foreach ($concursos as $concursoReal) {
            $numeroConcurso = (int) $concursoReal->concurso;

            if ($numeroConcurso <= $inicioConcurso) {
                continue;
            }

            $historico = $this->historicalDataService->getUntilContest($numeroConcurso - 1);

            if ($historico->isEmpty()) {
                continue;
            }

            $concursoBase = LotofacilConcurso::query()
                ->where('concurso', $numeroConcurso - 1)
                ->first();

            if (! $concursoBase) {
                continue;
            }

            $frequencyContext = $this->frequencyAnalysisService->analyze($historico);
            $delayContext = $this->delayAnalysisService->analyze($historico);
            $correlationContext = $this->correlationAnalysisService->analyze($historico);
            $structureContext = $this->structureAnalysisService->analyze($historico);
            $cycleContext = $this->cycleAnalysisService->analyze($historico);

            $targetCandidates = (int) config('lottus.generator.target_candidates', 200);

            $candidateWeights = [
                'frequency' => (float) config('lottus.weights.frequency', 0.25),
                'delay' => (float) config('lottus.weights.delay', 0.25),
                'correlation' => (float) config('lottus.weights.correlation', 0.25),
                'cycle' => (float) config('lottus.weights.cycle', 0.25),
                'faltantes' => $cycleContext['faltantes'] ?? [],
                'last_draw_numbers' => $this->extractNumbers($concursoBase),
                'scores' => $cycleContext['scores'] ?? [],
                'cycle_scores' => $cycleContext['scores'] ?? [],
            ];

            $candidateGames = $this->candidateGeneratorService->generate(
                $targetCandidates,
                $frequencyContext,
                $delayContext,
                $correlationContext,
                $structureContext,
                $candidateWeights
            );

            if (empty($candidateGames)) {
                continue;
            }

            $resultadoReal = $this->extractNumbers($concursoReal);

            $bestRaw = 0;
            $bestRawGame = [];

            foreach ($candidateGames as $game) {
                $hits = count(array_intersect($game, $resultadoReal));

                if ($hits > $bestRaw) {
                    $bestRaw = $hits;
                    $bestRawGame = $game;
                }
            }

            $candidates = [];

            foreach ($candidateGames as $game) {
                $candidates[] = [
                    'dezenas' => $game,
                    'profile' => 'aggressive',
                    'cycle_missing' => $cycleContext['faltantes'] ?? [],
                ];
            }

            $rankedGames = $this->gameScoringService->rank(
                $candidates,
                $frequencyContext,
                $delayContext,
                $correlationContext,
                $structureContext,
                $concursoBase
            );

            if (empty($rankedGames)) {
                continue;
            }

            $selectedGames = $this->portfolioOptimizerService->optimize($rankedGames, $quantidadeJogos);

            $melhorAcertoConcurso = 0;
            $melhorJogoConcurso = [];

            foreach ($selectedGames as $game) {
                $acertos = count(array_intersect($game['dezenas'], $resultadoReal));

                $resumo['jogos_gerados']++;

                if ($acertos >= 11) {
                    $resumo['faixas'][$acertos]++;
                }

                if ($acertos > $melhorAcertoConcurso) {
                    $melhorAcertoConcurso = $acertos;
                    $melhorJogoConcurso = $game['dezenas'];
                }

                if ($acertos > $resumo['melhor_resultado']['acertos']) {
                    $resumo['melhor_resultado'] = [
                        'concurso' => $numeroConcurso,
                        'acertos' => $acertos,
                        'jogo' => $game['dezenas'],
                        'resultado' => $resultadoReal,
                    ];
                }
            }

            $resumo['concursos_testados']++;
            $resumo['acertos_por_concurso'][] = [
                'concurso' => $numeroConcurso,
                'melhor_acerto' => $melhorAcertoConcurso,
                'jogo' => $melhorJogoConcurso,
                'resultado' => $resultadoReal,
            ];

            $resumo['diagnostico'][] = [
                'concurso' => $numeroConcurso,
                'raw' => $bestRaw,
                'raw_jogo' => $bestRawGame,
                'selected' => $melhorAcertoConcurso,
                'selected_jogo' => $melhorJogoConcurso,
                'loss' => $bestRaw - $melhorAcertoConcurso,
                'resultado' => $resultadoReal,
            ];
        }

        $resumo['taxas'] = $this->calculateRates(
            $resumo['faixas'],
            max($resumo['jogos_gerados'], 1)
        );

        return $resumo;
    }

    protected function calculateRates(array $faixas, int $totalJogos): array
    {
        $taxas = [];

        foreach ($faixas as $faixa => $quantidade) {
            $taxas[$faixa] = round(($quantidade / $totalJogos) * 100, 4);
        }

        return $taxas;
    }

    protected function extractNumbers(LotofacilConcurso $concurso): array
    {
        $numbers = [];

        for ($i = 1; $i <= 15; $i++) {
            $numbers[] = (int) $concurso->{'bola' . $i};
        }

        sort($numbers);

        return $numbers;
    }
}