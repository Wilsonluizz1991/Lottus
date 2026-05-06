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
use App\Services\Lottus\Generation\HighCeilingCandidateGeneratorService;

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
        protected HighCeilingCandidateGeneratorService $highCeilingCandidateGeneratorService,
        protected GameScoringService $gameScoringService,
        protected PortfolioOptimizerService $portfolioOptimizerService,
        protected CoreClusterPreservationService $coreClusterPreservationService,
    ) {
    }

    public function run(
        int $inicioConcurso,
        int $fimConcurso,
        int $quantidadeJogos = 1,
        ?array $portfolioTuning = null,
        ?int $seed = null
    ): array
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
            'quantidade_candidatos_por_concurso' => (int) config('lottus.generator.target_candidates', 200)
                + ((bool) config('lottus.generator.elite.enabled', true)
                    ? (int) config('lottus.generator.elite.target_candidates', 0)
                    : 0),
            'concursos_testados' => 0,
            'jogos_gerados' => 0,
            'faixas' => [
                11 => 0,
                12 => 0,
                13 => 0,
                14 => 0,
                15 => 0,
            ],
            'raw_melhor_faixas' => [
                11 => 0,
                12 => 0,
                13 => 0,
                14 => 0,
                15 => 0,
            ],
            'raw_14_15_total' => 0,
            'raw_14_15_preservados' => 0,
            'raw_14_15_loss' => 0,
            'near_15_raw_candidates' => 0,
            'raw_15_candidates' => 0,
            'strategy_stats' => [],
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

            if ($seed !== null) {
                mt_srand($seed + $numeroConcurso);
                srand($seed + $numeroConcurso);
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

            $candidatePayloads = $this->normalizeCandidatePayloads(
                $candidateGames,
                'baseline_explosive',
                'explosive',
                $cycleContext['faltantes'] ?? []
            );

            $eliteCandidatePayloads = $this->highCeilingCandidateGeneratorService->generate(
                $targetCandidates,
                $frequencyContext,
                $delayContext,
                $correlationContext,
                $structureContext,
                $candidateWeights,
                $historico
            );

            $candidatePayloads = $this->mergeCandidatePayloads($candidatePayloads, $eliteCandidatePayloads);

            if (empty($candidatePayloads)) {
                continue;
            }

            $resultadoReal = $this->extractNumbers($concursoReal);

            $bestRaw = 0;
            $bestRawGame = [];
            $bestRawCandidate = [];

            foreach ($candidatePayloads as $candidate) {
                $game = $candidate['dezenas'] ?? [];
                $hits = count(array_intersect($game, $resultadoReal));
                $strategy = $candidate['strategy'] ?? 'unknown';

                $this->recordStrategyStats($resumo['strategy_stats'], $strategy, $hits);

                if ($hits === 14) {
                    $resumo['near_15_raw_candidates']++;
                }

                if ($hits === 15) {
                    $resumo['raw_15_candidates']++;
                }

                if ($hits > $bestRaw) {
                    $bestRaw = $hits;
                    $bestRawGame = $game;
                    $bestRawCandidate = $candidate;
                }
            }

            $rankedGames = $this->gameScoringService->rank(
                $candidatePayloads,
                $frequencyContext,
                $delayContext,
                $correlationContext,
                $structureContext,
                $concursoBase,
                $historico
            );

            $rankedGames = $this->coreClusterPreservationService->preserve($rankedGames);

            if (empty($rankedGames)) {
                continue;
            }

            $rankingAudit = $this->auditRanking($rankedGames, $bestRawGame, $resultadoReal);

            $selectedGames = $portfolioOptimizer->optimize($rankedGames, $quantidadeJogos);

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

            if ($bestRaw >= 11 && $bestRaw <= 15) {
                $resumo['raw_melhor_faixas'][$bestRaw]++;
            }

            $rawNoSelected = $this->selectedContainsGame($selectedGames, $bestRawGame);

            if ($bestRaw >= 14) {
                $resumo['raw_14_15_total']++;

                if ($melhorAcertoConcurso >= $bestRaw && $rawNoSelected) {
                    $resumo['raw_14_15_preservados']++;
                } else {
                    $resumo['raw_14_15_loss']++;
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
                'raw_strategy' => $bestRawCandidate['strategy'] ?? null,
                'raw_rank' => $rankingAudit['rank'],
                'raw_score' => $rankingAudit['score'],
                'raw_extreme_score' => $rankingAudit['extreme_score'],
                'raw_stat_score' => $rankingAudit['stat_score'],
                'raw_structure_score' => $rankingAudit['structure_score'],
                'raw_historical_peak_score' => $rankingAudit['historical_peak_score'],
                'raw_historical_max_hits' => $rankingAudit['historical_max_hits'],
                'raw_historical_13_plus' => $rankingAudit['historical_13_plus'],
                'raw_historical_14_plus' => $rankingAudit['historical_14_plus'],
                'selected' => $melhorAcertoConcurso,
                'selected_jogo' => $melhorJogoConcurso,
                'selected_strategy' => $selectedAudit['strategy'] ?? null,
                'raw_no_selected' => $rawNoSelected,
                'selected_rank' => $selectedAudit['rank'] ?? null,
                'selected_score' => $selectedAudit['score'] ?? null,
                'selected_extreme_score' => $selectedAudit['extreme_score'] ?? null,
                'selected_stat_score' => $selectedAudit['stat_score'] ?? null,
                'selected_structure_score' => $selectedAudit['structure_score'] ?? null,
                'loss' => $bestRaw - $melhorAcertoConcurso,
                'motivo_loss' => $this->diagnoseLoss($rankingAudit, $selectedAudit, $bestRaw, $melhorAcertoConcurso),
                'raw_missing_numbers' => array_values(array_diff($resultadoReal, $bestRawGame)),
                'raw_extra_numbers' => array_values(array_diff($bestRawGame, $resultadoReal)),
                'selected_missing_numbers' => array_values(array_diff($resultadoReal, $melhorJogoConcurso)),
                'selected_extra_numbers' => array_values(array_diff($melhorJogoConcurso, $resultadoReal)),
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
                'historical_peak_score' => round((float) ($game['historical_peak_score'] ?? 0), 6),
                'historical_max_hits' => (int) ($game['historical_max_hits'] ?? 0),
                'historical_13_plus' => (int) ($game['historical_13_plus'] ?? 0),
                'historical_14_plus' => (int) ($game['historical_14_plus'] ?? 0),
                'strategy' => $game['strategy'] ?? null,
                'hits' => count(array_intersect($game['dezenas'] ?? [], $resultadoReal)),
            ];
        }

        return [
            'rank' => null,
            'score' => null,
            'extreme_score' => null,
            'stat_score' => null,
            'structure_score' => null,
            'historical_peak_score' => null,
            'historical_max_hits' => null,
            'historical_13_plus' => null,
            'historical_14_plus' => null,
            'strategy' => null,
            'hits' => count(array_intersect($targetGame, $resultadoReal)),
        ];
    }

    protected function selectedContainsGame(array $selectedGames, array $targetGame): bool
    {
        $targetKey = $this->gameKey($targetGame);

        foreach ($selectedGames as $game) {
            if ($this->gameKey($game['dezenas'] ?? []) === $targetKey) {
                return true;
            }
        }

        return false;
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

        $taxas['13_plus'] = round(((($faixas[13] ?? 0) + ($faixas[14] ?? 0) + ($faixas[15] ?? 0)) / $totalJogos) * 100, 4);
        $taxas['14_plus'] = round(((($faixas[14] ?? 0) + ($faixas[15] ?? 0)) / $totalJogos) * 100, 4);

        return $taxas;
    }

    protected function recordStrategyStats(array &$stats, string $strategy, int $hits): void
    {
        if (! isset($stats[$strategy])) {
            $stats[$strategy] = [
                'candidates' => 0,
                'best_hits' => 0,
                'raw_13' => 0,
                'raw_14' => 0,
                'raw_15' => 0,
            ];
        }

        $stats[$strategy]['candidates']++;
        $stats[$strategy]['best_hits'] = max($stats[$strategy]['best_hits'], $hits);

        if ($hits === 13) {
            $stats[$strategy]['raw_13']++;
        } elseif ($hits === 14) {
            $stats[$strategy]['raw_14']++;
        } elseif ($hits === 15) {
            $stats[$strategy]['raw_15']++;
        }
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
