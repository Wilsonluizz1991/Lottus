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
use App\Services\Lottus\Generation\CoreClusterPreservationService;

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
        protected PortfolioOptimizerService $portfolioOptimizerService,
        protected CoreClusterPreservationService $coreClusterPreservationService,
    ) {
    }

    public function run(int $inicioConcurso, int $fimConcurso, int $quantidadeJogos = 1, ?array $portfolioTuning = null): array
    {
        if ($inicioConcurso >= $fimConcurso) {
            throw new \InvalidArgumentException('O concurso inicial deve ser menor que o concurso final.');
        }

        if ($quantidadeJogos < 1) {
            throw new \InvalidArgumentException('A quantidade de jogos deve ser no mínimo 1.');
        }

        $portfolioOptimizer = $portfolioTuning
            ? new PortfolioOptimizerService($portfolioTuning)
            : $this->portfolioOptimizerService;

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

            $rankedGames = $this->coreClusterPreservationService->preserve($rankedGames);

            if (empty($rankedGames)) {
                continue;
            }

            $rankingAudit = $this->auditRanking($rankedGames, $bestRawGame, $resultadoReal);

            $oracleMode = (bool) config('lottus.backtest.oracle_mode', false);

if ($oracleMode) {
    usort($rankedGames, function ($a, $b) use ($resultadoReal) {
        $hitsA = count(array_intersect($a['dezenas'] ?? [], $resultadoReal));
        $hitsB = count(array_intersect($b['dezenas'] ?? [], $resultadoReal));

        return $hitsB <=> $hitsA;
    });

    $selectedGames = array_slice($rankedGames, 0, $quantidadeJogos);
} else {
    $selectedGames = $portfolioOptimizer->optimize($rankedGames, $quantidadeJogos);
}

            $melhorAcertoConcurso = 0;
            $melhorJogoConcurso = [];
            $selectedAudit = null;

            foreach ($selectedGames as $game) {
                $acertos = count(array_intersect($game['dezenas'], $resultadoReal));

                $resumo['jogos_gerados']++;

                if ($acertos >= 11) {
                    $resumo['faixas'][$acertos]++;
                }

                if ($acertos > $melhorAcertoConcurso) {
                    $melhorAcertoConcurso = $acertos;
                    $melhorJogoConcurso = $game['dezenas'];
                    $selectedAudit = $this->auditSelectedGame($rankedGames, $game['dezenas'], $resultadoReal);
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
                'raw_rank' => $rankingAudit['rank'],
                'raw_score' => $rankingAudit['score'],
                'raw_extreme_score' => $rankingAudit['extreme_score'],
                'raw_stat_score' => $rankingAudit['stat_score'],
                'raw_structure_score' => $rankingAudit['structure_score'],
                'selected' => $melhorAcertoConcurso,
                'selected_jogo' => $melhorJogoConcurso,
                'selected_rank' => $selectedAudit['rank'] ?? null,
                'selected_score' => $selectedAudit['score'] ?? null,
                'selected_extreme_score' => $selectedAudit['extreme_score'] ?? null,
                'selected_stat_score' => $selectedAudit['stat_score'] ?? null,
                'selected_structure_score' => $selectedAudit['structure_score'] ?? null,
                'loss' => $bestRaw - $melhorAcertoConcurso,
                'motivo_loss' => $this->diagnoseLoss($rankingAudit, $selectedAudit, $bestRaw, $melhorAcertoConcurso),
                'resultado' => $resultadoReal,
            ];
        }

        $resumo['taxas'] = $this->calculateRates(
            $resumo['faixas'],
            max($resumo['jogos_gerados'], 1)
        );

        return $resumo;
    }

    protected function auditRanking(array $rankedGames, array $targetGame, array $resultadoReal): array
    {
        $targetKey = $this->gameKey($targetGame);

        foreach ($rankedGames as $index => $game) {
            if ($this->gameKey($game['dezenas'] ?? []) !== $targetKey) {
                continue;
            }

            return [
                'rank' => $index + 1,
                'score' => round((float) ($game['score'] ?? 0), 6),
                'extreme_score' => round((float) ($game['extreme_score'] ?? 0), 6),
                'stat_score' => round((float) ($game['stat_score'] ?? 0), 6),
                'structure_score' => round((float) ($game['structure_score'] ?? 0), 6),
                'hits' => count(array_intersect($game['dezenas'] ?? [], $resultadoReal)),
            ];
        }

        return [
            'rank' => null,
            'score' => null,
            'extreme_score' => null,
            'stat_score' => null,
            'structure_score' => null,
            'hits' => count(array_intersect($targetGame, $resultadoReal)),
        ];
    }

    protected function auditSelectedGame(array $rankedGames, array $selectedGame, array $resultadoReal): array
    {
        return $this->auditRanking($rankedGames, $selectedGame, $resultadoReal);
    }

    protected function diagnoseLoss(array $rawAudit, ?array $selectedAudit, int $bestRaw, int $selected): string
    {
        if ($bestRaw <= $selected) {
            return 'sem_loss';
        }

        if (($rawAudit['rank'] ?? null) === null) {
            return 'raw_nao_encontrado_no_ranking';
        }

        if (($rawAudit['rank'] ?? 999999) <= 5) {
            return 'raw_estava_no_top_5_mas_optimizer_nao_preservou';
        }

        if (($rawAudit['rank'] ?? 999999) <= 20) {
            return 'raw_estava_no_top_20_mas_optimizer_preferiu_outro';
        }

        if ($selectedAudit && ($rawAudit['score'] ?? 0) < ($selectedAudit['score'] ?? 0)) {
            return 'scoring_rebaixou_raw_vencedor';
        }

        return 'raw_ficou_baixo_no_ranking';
    }

    protected function gameKey(array $game): string
    {
        $game = array_values(array_unique(array_map('intval', $game)));
        sort($game);

        return implode('-', $game);
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