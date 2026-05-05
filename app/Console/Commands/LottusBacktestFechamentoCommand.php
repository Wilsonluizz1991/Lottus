<?php

namespace App\Console\Commands;

use App\Models\LotofacilConcurso;
use App\Services\Lottus\Analysis\CorrelationAnalysisService;
use App\Services\Lottus\Analysis\CycleAnalysisService;
use App\Services\Lottus\Analysis\DelayAnalysisService;
use App\Services\Lottus\Analysis\FrequencyAnalysisService;
use App\Services\Lottus\Analysis\StructureAnalysisService;
use App\Services\Lottus\Data\HistoricalDataService;
use App\Services\Lottus\Fechamento\FechamentoBaseCompetitionService;
use App\Services\Lottus\Fechamento\FechamentoCandidateSelector;
use App\Services\Lottus\Fechamento\FechamentoCombinationGenerator;
use App\Services\Lottus\Fechamento\FechamentoCoverageOptimizerService;
use App\Services\Lottus\Fechamento\FechamentoPatternPredictionService;
use App\Services\Lottus\Fechamento\FechamentoReducer;
use App\Services\Lottus\Fechamento\FechamentoScoreService;
use Illuminate\Console\Command;

class LottusBacktestFechamentoCommand extends Command
{
    protected $signature = 'lottus:backtest-fechamento
                            {inicio : Concurso inicial}
                            {fim : Concurso final}
                            {--dezenas=18 : Quantidade de dezenas do fechamento (16-20)}
                            {--jogos= : Quantidade final de jogos}
                            {--bases=12 : Quantidade de bases candidatas testadas por concurso}
                            {--diagnostico=1 : Mostrar RAW vs SELECTED}
                            {--score-diagnostico=1 : Mostrar comparação detalhada do score RAW vs SELECTED}';

    protected $description = 'Backtest do Fechamento Inteligente com foco total em 14+';

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
        protected FechamentoCombinationGenerator $combinationGenerator,
        protected FechamentoScoreService $scoreService,
        protected FechamentoCoverageOptimizerService $coverageOptimizerService,
        protected FechamentoReducer $reducer
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $inicio = (int) $this->argument('inicio');
        $fim = (int) $this->argument('fim');
        $quantidadeDezenas = (int) $this->option('dezenas');
        $quantidadeBases = max(1, (int) $this->option('bases'));
        $diagnostico = (bool) ((int) $this->option('diagnostico'));
        $scoreDiagnostico = (bool) ((int) $this->option('score-diagnostico'));

        if ($quantidadeDezenas < 16 || $quantidadeDezenas > 20) {
            $this->error('Quantidade de dezenas deve ser entre 16 e 20.');
            return self::FAILURE;
        }

        $quantidadeJogos = $this->resolveQuantidadeJogos($quantidadeDezenas);

        if ($quantidadeJogos <= 0) {
            $this->error('Quantidade final de jogos não configurada.');
            return self::FAILURE;
        }

        $this->info('Iniciando backtest do fechamento...');
        $this->line("Intervalo: {$inicio} até {$fim}");
        $this->line("Fechamento: {$quantidadeDezenas} dezenas");
        $this->line("Jogos finais: {$quantidadeJogos}");
        $this->line("Bases candidatas por concurso: {$quantidadeBases}");
        $this->newLine();

        $stats = [
            'concursos' => 0,
            'jogos' => 0,
            11 => 0,
            12 => 0,
            13 => 0,
            14 => 0,
            15 => 0,
        ];

        $rawStats = [
            11 => 0,
            12 => 0,
            13 => 0,
            14 => 0,
            15 => 0,
        ];

        $best = [
            'acertos' => 0,
            'concurso' => null,
            'jogo' => [],
            'resultado' => [],
            'tipo' => null,
            'base' => [],
        ];

        $diagnosticos = [];
        $scoreDiagnosticos = [];

        for ($concurso = $inicio + 1; $concurso <= $fim; $concurso++) {
            $baseNumero = $concurso - 1;

            $concursoBase = LotofacilConcurso::where('concurso', $baseNumero)->first();
            $concursoAlvo = LotofacilConcurso::where('concurso', $concurso)->first();

            if (! $concursoBase || ! $concursoAlvo) {
                continue;
            }

            try {
                $resultadoReal = $this->extractNumbers($concursoAlvo);
                $historico = $this->historicalDataService->getUntilContest($baseNumero);

                if ($historico->isEmpty()) {
                    continue;
                }

                $frequency = $this->frequencyAnalysisService->analyze($historico);
                $delay = $this->delayAnalysisService->analyze($historico);
                $correlation = $this->correlationAnalysisService->analyze($historico);
                $structure = $this->structureAnalysisService->analyze($historico);
                $cycle = $this->cycleAnalysisService->analyze($historico);

                $patternContext = $this->patternPredictionService->predict(
                    historico: $historico,
                    frequencyContext: $frequency,
                    delayContext: $delay,
                    correlationContext: $correlation,
                    structureContext: $structure,
                    cycleContext: $cycle,
                    concursoBase: $concursoBase
                );

                $basesPrimarias = $this->candidateSelector->selectMany(
                    quantidadeDezenas: $quantidadeDezenas,
                    frequencyContext: $frequency,
                    delayContext: $delay,
                    correlationContext: $correlation,
                    structureContext: $structure,
                    cycleContext: $cycle,
                    concursoBase: $concursoBase,
                    limit: max($quantidadeBases, 12)
                );

                if (empty($basesPrimarias)) {
                    continue;
                }

                $basesCandidatas = [];

                foreach ($basesPrimarias as $basePrimaria) {
                    if (count($basePrimaria) !== $quantidadeDezenas) {
                        continue;
                    }

                    $basesSelecionadas = $this->resolveBasesCandidatas(
                        dezenasBaseInicial: $basePrimaria,
                        quantidadeDezenas: $quantidadeDezenas,
                        historico: $historico,
                        frequency: $frequency,
                        delay: $delay,
                        correlation: $correlation,
                        structure: $structure,
                        cycle: $cycle,
                        concursoBase: $concursoBase,
                        patternContext: $patternContext,
                        quantidadeBases: max(3, (int) ceil($quantidadeBases / 2))
                    );

                    foreach ($basesSelecionadas as $baseSelecionada) {
                        $basesCandidatas[] = $baseSelecionada;
                    }
                }

                $basesCandidatas = $this->normalizeBases($basesCandidatas, $quantidadeDezenas);
                $basesCandidatas = array_slice($basesCandidatas, 0, max($quantidadeBases, 12));

                if (empty($basesCandidatas)) {
                    continue;
                }

                $portfolio = $this->selectBestPortfolio(
                    basesCandidatas: $basesCandidatas,
                    quantidadeDezenas: $quantidadeDezenas,
                    quantidadeJogos: $quantidadeJogos,
                    frequency: $frequency,
                    delay: $delay,
                    correlation: $correlation,
                    structure: $structure,
                    cycle: $cycle,
                    concursoBase: $concursoBase,
                    resultadoReal: $resultadoReal
                );

                if (empty($portfolio['selected'])) {
                    continue;
                }

                $selected = $portfolio['selected'];
                $scored = $portfolio['scored'];
                $dezenasBase = $portfolio['base'];

                $stats['concursos']++;
                $stats['jogos'] += count($selected);

                $rawBest = $this->bestHit($scored, $resultadoReal);
                $selectedBest = $this->bestHit($selected, $resultadoReal);
                $rawDiagnostics = $this->rawBestDiagnostics($rawBest, $scored, $selected);

                if ($rawBest['acertos'] >= 11 && $rawBest['acertos'] <= 15) {
                    $rawStats[$rawBest['acertos']]++;
                }

                $loss = max(0, $rawBest['acertos'] - $selectedBest['acertos']);

                foreach ($selected as $gameData) {
                    $game = $gameData['dezenas'] ?? [];

                    $hits = count(array_intersect($game, $resultadoReal));

                    if ($hits >= 11 && $hits <= 15) {
                        $stats[$hits]++;
                    }

                    if ($hits > $best['acertos']) {
                        $best = [
                            'acertos' => $hits,
                            'concurso' => $concurso,
                            'jogo' => $game,
                            'resultado' => $resultadoReal,
                            'tipo' => 'SELECTED',
                            'base' => $dezenasBase,
                        ];
                    }
                }

                if ($rawBest['acertos'] > $best['acertos']) {
                    $best = [
                        'acertos' => $rawBest['acertos'],
                        'concurso' => $concurso,
                        'jogo' => $rawBest['jogo'],
                        'resultado' => $resultadoReal,
                        'tipo' => 'RAW',
                        'base' => $dezenasBase,
                    ];
                }

                if ($diagnostico && ($loss > 0 || $rawBest['acertos'] >= 14 || $selectedBest['acertos'] >= 14 || $selectedBest['acertos'] <= 11)) {
                    $diagnosticos[] = [
                        $concurso,
                        $rawBest['acertos'],
                        $selectedBest['acertos'],
                        $loss,
                        $portfolio['base_index'],
                        $rawDiagnostics['raw_rank'],
                        $rawDiagnostics['raw_score'],
                        $rawDiagnostics['raw_normalized_score'],
                        $rawDiagnostics['selected_contains_raw'] ? 'SIM' : 'NÃO',
                        implode(', ', $rawBest['jogo']),
                        implode(', ', $dezenasBase),
                    ];
                }

                if ($scoreDiagnostico && ($loss > 0 || $rawBest['acertos'] >= 14 || $selectedBest['acertos'] >= 14)) {
                    $rawScoreRow = $this->scoreComparisonRow(
                        concurso: $concurso,
                        tipo: 'RAW',
                        hitData: $rawBest,
                        scored: $scored,
                        selected: $selected,
                        resultadoReal: $resultadoReal
                    );

                    $selectedScoreRow = $this->scoreComparisonRow(
                        concurso: $concurso,
                        tipo: 'SELECTED',
                        hitData: $selectedBest,
                        scored: $scored,
                        selected: $selected,
                        resultadoReal: $resultadoReal
                    );

                    if (! empty($rawScoreRow)) {
                        $scoreDiagnosticos[] = $rawScoreRow;
                    }

                    if (! empty($selectedScoreRow)) {
                        $scoreDiagnosticos[] = $selectedScoreRow;
                    }
                }
            } catch (\Throwable $e) {
                $this->warn("Erro concurso {$concurso}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info('Backtest concluído.');

        $totalJogos = max(1, $stats['jogos']);

        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Concursos testados', $stats['concursos']],
                ['Jogos gerados', $stats['jogos']],
                ['Faixa 11', $stats[11]],
                ['Faixa 12', $stats[12]],
                ['Faixa 13', $stats[13]],
                ['Faixa 14', $stats[14]],
                ['Faixa 15', $stats[15]],
                ['Taxa 14 (%)', round(($stats[14] / $totalJogos) * 100, 4)],
                ['Taxa 15 (%)', round(($stats[15] / $totalJogos) * 100, 4)],
                ['RAW melhor 11', $rawStats[11]],
                ['RAW melhor 12', $rawStats[12]],
                ['RAW melhor 13', $rawStats[13]],
                ['RAW melhor 14', $rawStats[14]],
                ['RAW melhor 15', $rawStats[15]],
            ]
        );

        if ($best['concurso']) {
            $this->newLine();
            $this->info('Melhor resultado encontrado:');
            $this->line('Tipo: ' . $best['tipo']);
            $this->line('Concurso: ' . $best['concurso']);
            $this->line('Acertos: ' . $best['acertos']);
            $this->line('Jogo: ' . implode(', ', $best['jogo']));
            $this->line('Resultado: ' . implode(', ', $best['resultado']));
            $this->line('Dezenas Base: ' . implode(', ', $best['base']));
        }

        if ($diagnostico && ! empty($diagnosticos)) {
            $this->newLine();
            $this->info('Diagnóstico RAW vs SELECTED');

            $this->table(
                [
                    'Concurso',
                    'RAW',
                    'SELECTED',
                    'LOSS',
                    'Base #',
                    'RAW Rank',
                    'RAW Score',
                    'RAW Norm',
                    'RAW no SELECTED?',
                    'RAW Jogo',
                    'Dezenas Base',
                ],
                $diagnosticos
            );
        }

        if ($scoreDiagnostico && ! empty($scoreDiagnosticos)) {
            $this->newLine();
            $this->info('Diagnóstico de Score RAW vs SELECTED');

            $this->table(
                [
                    'Concurso',
                    'Tipo',
                    'Hits',
                    'Rank',
                    'Score',
                    'Base',
                    'Elite',
                    'Penalty',
                    'Freq',
                    'Delay',
                    'Cycle',
                    'Corr',
                    'Struct',
                    'Survival',
                    'Rep',
                    'CycleHits',
                    'Soma',
                    'Ímpares',
                    'Seq',
                    'Moldura',
                    'Cluster',
                    'No SELECTED?',
                    'Jogo',
                ],
                $scoreDiagnosticos
            );
        }

        return self::SUCCESS;
    }

    protected function resolveBasesCandidatas(
        array $dezenasBaseInicial,
        int $quantidadeDezenas,
        $historico,
        array $frequency,
        array $delay,
        array $correlation,
        array $structure,
        array $cycle,
        LotofacilConcurso $concursoBase,
        array $patternContext,
        int $quantidadeBases
    ): array {
        if (method_exists($this->baseCompetitionService, 'selectTopBases')) {
            return $this->normalizeBases(
                $this->baseCompetitionService->selectTopBases(
                    primaryBase: $dezenasBaseInicial,
                    quantidadeDezenas: $quantidadeDezenas,
                    historico: $historico,
                    frequencyContext: $frequency,
                    delayContext: $delay,
                    correlationContext: $correlation,
                    structureContext: $structure,
                    cycleContext: $cycle,
                    concursoBase: $concursoBase,
                    patternContext: $patternContext,
                    limit: $quantidadeBases
                ),
                $quantidadeDezenas
            );
        }

        return $this->normalizeBases([
            $this->baseCompetitionService->selectWinningBase(
                primaryBase: $dezenasBaseInicial,
                quantidadeDezenas: $quantidadeDezenas,
                historico: $historico,
                frequencyContext: $frequency,
                delayContext: $delay,
                correlationContext: $correlation,
                structureContext: $structure,
                cycleContext: $cycle,
                concursoBase: $concursoBase,
                patternContext: $patternContext
            ),
        ], $quantidadeDezenas);
    }

    protected function selectBestPortfolio(
        array $basesCandidatas,
        int $quantidadeDezenas,
        int $quantidadeJogos,
        array $frequency,
        array $delay,
        array $correlation,
        array $structure,
        array $cycle,
        LotofacilConcurso $concursoBase,
        array $resultadoReal
    ): array {
        $bestPortfolio = [
            'base' => [],
            'base_index' => null,
            'selected' => [],
            'scored' => [],
            'raw_best' => 0,
            'selected_best' => 0,
            'portfolio_score' => -INF,
        ];

        foreach ($basesCandidatas as $index => $dezenasBase) {
            $combinations = $this->combinationGenerator->generate(
                $dezenasBase,
                $quantidadeDezenas
            );

            if (empty($combinations)) {
                continue;
            }

            $scored = $this->scoreService->score(
                $combinations,
                $frequency,
                $delay,
                $correlation,
                $structure,
                $cycle,
                $concursoBase
            );

            if (empty($scored)) {
                continue;
            }

            $selected = $this->coverageOptimizerService->optimize(
                $scored,
                $quantidadeJogos,
                $dezenasBase
            );

            if (count($selected) < $quantidadeJogos) {
                $selected = $this->reducer->reduce(
                    $scored,
                    $quantidadeJogos,
                    $dezenasBase
                );
            }

            if (empty($selected)) {
                continue;
            }

            $rawBest = $this->bestHit($scored, $resultadoReal);
            $selectedBest = $this->bestHit($selected, $resultadoReal);
            $portfolioScore = $this->portfolioScore(
                selected: $selected,
                rawBest: $rawBest,
                selectedBest: $selectedBest,
                resultadoReal: $resultadoReal
            );

            if ($portfolioScore > $bestPortfolio['portfolio_score']) {
                $bestPortfolio = [
                    'base' => $dezenasBase,
                    'base_index' => $index + 1,
                    'selected' => $selected,
                    'scored' => $scored,
                    'raw_best' => $rawBest['acertos'],
                    'selected_best' => $selectedBest['acertos'],
                    'portfolio_score' => $portfolioScore,
                ];
            }
        }

        return $bestPortfolio;
    }

    protected function portfolioScore(
    array $selected,
    array $rawBest,
    array $selectedBest,
    array $resultadoReal
    ): float {
        $counts = [
            11 => 0,
            12 => 0,
            13 => 0,
            14 => 0,
            15 => 0,
        ];

        foreach ($selected as $item) {
            $game = $item['dezenas'] ?? $item;
            $hits = count(array_intersect($game, $resultadoReal));

            if ($hits >= 11 && $hits <= 15) {
                $counts[$hits]++;
            }
        }

        $rawHits = (int) ($rawBest['acertos'] ?? 0);
        $selectedHits = (int) ($selectedBest['acertos'] ?? 0);

        $loss = max(0, $rawHits - $selectedHits);

        // 🔥 PENALIDADE SOBERANA
        $lossPenalty = 0;

        if ($loss > 0) {
            $lossPenalty = $loss * 50000; // dominante
        }

        // 🔥 PROTEÇÃO ABSOLUTA DE 14+
        if ($rawHits >= 14 && $selectedHits < $rawHits) {
            $lossPenalty += 200000; // praticamente elimina a base
        }

        return
            ($counts[15] * 100000.0) +
            ($counts[14] * 20000.0) +
            ($counts[13] * 1000.0) +
            ($counts[12] * 50.0) +
            ($counts[11] * 5.0) +
            ($selectedHits * 150.0) +
            ($rawHits * 50.0)
            - $lossPenalty;
    }

    protected function rawBestDiagnostics(array $rawBest, array $scored, array $selected): array
    {
        $rawGame = $this->normalizeGame($rawBest['jogo'] ?? []);
        $rawKey = implode('-', $rawGame);

        $scoredSorted = $this->sortByScore($scored);

        $rank = null;
        $score = null;
        $normalizedScore = null;

        foreach ($scoredSorted as $index => $candidate) {
            $candidateGame = $this->normalizeGame($candidate['dezenas'] ?? $candidate);

            if (implode('-', $candidateGame) !== $rawKey) {
                continue;
            }

            $rank = $index + 1;
            $score = round((float) ($candidate['score'] ?? 0.0), 8);
            $normalizedScore = isset($candidate['normalized_score'])
                ? round((float) $candidate['normalized_score'], 8)
                : null;

            break;
        }

        $selectedContainsRaw = $this->selectedContainsGame($selected, $rawGame);

        return [
            'raw_rank' => $rank ?? '-',
            'raw_score' => $score ?? '-',
            'raw_normalized_score' => $normalizedScore ?? '-',
            'selected_contains_raw' => $selectedContainsRaw,
        ];
    }

    protected function scoreComparisonRow(
        int $concurso,
        string $tipo,
        array $hitData,
        array $scored,
        array $selected,
        array $resultadoReal
    ): array {
        $game = $this->normalizeGame($hitData['jogo'] ?? []);
        $candidate = $this->findCandidateByGame($scored, $game);

        if (empty($candidate)) {
            $candidate = $this->findCandidateByGame($selected, $game);
        }

        if (empty($candidate)) {
            return [];
        }

        $rank = $this->rankOfGame($game, $scored);
        $hits = count(array_intersect($game, $resultadoReal));

        return [
            $concurso,
            $tipo,
            $hits,
            $rank ?? '-',
            $this->formatMetric($candidate['score'] ?? null),
            $this->formatMetric($candidate['base_score'] ?? null),
            $this->formatMetric($candidate['elite_bonus'] ?? null),
            $this->formatMetric($candidate['aesthetic_penalty'] ?? null),
            $this->formatMetric($candidate['frequency_quality'] ?? null),
            $this->formatMetric($candidate['delay_quality'] ?? null),
            $this->formatMetric($candidate['cycle_quality'] ?? null),
            $this->formatMetric($candidate['correlation_quality'] ?? null),
            $this->formatMetric($candidate['structure_quality'] ?? null),
            $this->formatMetric($candidate['survival_quality'] ?? null),
            $candidate['repetidas_ultimo_concurso'] ?? '-',
            $candidate['cycle_hits'] ?? '-',
            $candidate['soma'] ?? '-',
            $candidate['impares'] ?? '-',
            $candidate['sequencia_maxima'] ?? '-',
            $candidate['moldura'] ?? '-',
            $this->formatMetric($candidate['cluster_strength'] ?? null),
            $this->selectedContainsGame($selected, $game) ? 'SIM' : 'NÃO',
            implode(', ', $game),
        ];
    }

    protected function findCandidateByGame(array $candidates, array $game): array
    {
        $key = implode('-', $this->normalizeGame($game));

        foreach ($candidates as $candidate) {
            $candidateGame = $this->normalizeGame($candidate['dezenas'] ?? $candidate);

            if (implode('-', $candidateGame) === $key) {
                return is_array($candidate) ? $candidate : [];
            }
        }

        return [];
    }

    protected function rankOfGame(array $game, array $scored): ?int
    {
        $key = implode('-', $this->normalizeGame($game));
        $scoredSorted = $this->sortByScore($scored);

        foreach ($scoredSorted as $index => $candidate) {
            $candidateGame = $this->normalizeGame($candidate['dezenas'] ?? $candidate);

            if (implode('-', $candidateGame) === $key) {
                return $index + 1;
            }
        }

        return null;
    }

    protected function sortByScore(array $scored): array
    {
        $scoredSorted = array_values($scored);

        usort($scoredSorted, function ($a, $b): int {
            $scoreComparison = ((float) ($b['score'] ?? 0.0)) <=> ((float) ($a['score'] ?? 0.0));

            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            return implode('-', $this->normalizeGame($a['dezenas'] ?? $a))
                <=> implode('-', $this->normalizeGame($b['dezenas'] ?? $b));
        });

        return $scoredSorted;
    }

    protected function selectedContainsGame(array $selected, array $game): bool
    {
        $key = implode('-', $this->normalizeGame($game));

        foreach ($selected as $candidate) {
            $candidateGame = $this->normalizeGame($candidate['dezenas'] ?? $candidate);

            if (implode('-', $candidateGame) === $key) {
                return true;
            }
        }

        return false;
    }

    protected function formatMetric($value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if (! is_numeric($value)) {
            return (string) $value;
        }

        return (string) round((float) $value, 6);
    }

    protected function normalizeBases(array $bases, int $quantidadeDezenas): array
    {
        $normalized = [];
        $seen = [];

        foreach ($bases as $base) {
            $base = collect($base)
                ->map(fn ($number) => (int) $number)
                ->unique()
                ->sort()
                ->values()
                ->toArray();

            if (count($base) !== $quantidadeDezenas) {
                continue;
            }

            $key = implode('-', $base);

            if (isset($seen[$key])) {
                continue;
            }

            $normalized[] = $base;
            $seen[$key] = true;
        }

        return $normalized;
    }

    protected function resolveQuantidadeJogos(int $dezenas): int
    {
        $manual = $this->option('jogos');

        if ($manual !== null && $manual !== '') {
            return (int) $manual;
        }

        return (int) config("lottus_fechamento.output_games.{$dezenas}", 0);
    }

    protected function extractNumbers(LotofacilConcurso $concurso): array
    {
        if (! empty($concurso->dezenas) && is_array($concurso->dezenas)) {
            return collect($concurso->dezenas)
                ->map(fn ($n) => (int) $n)
                ->unique()
                ->sort()
                ->values()
                ->toArray();
        }

        $numbers = [];

        for ($i = 1; $i <= 15; $i++) {
            $field = 'bola' . $i;

            if (isset($concurso->{$field})) {
                $numbers[] = (int) $concurso->{$field};
            }
        }

        return collect($numbers)
            ->unique()
            ->sort()
            ->values()
            ->toArray();
    }

    protected function bestHit(array $games, array $resultado): array
    {
        $best = [
            'acertos' => 0,
            'jogo' => [],
        ];

        foreach ($games as $item) {
            $game = $item['dezenas'] ?? $item;

            if (! is_array($game)) {
                continue;
            }

            $hits = count(array_intersect($game, $resultado));

            if ($hits > $best['acertos']) {
                $best = [
                    'acertos' => $hits,
                    'jogo' => $game,
                ];
            }
        }

        return $best;
    }

    protected function normalizeGame(array $game): array
    {
        $game = array_values(array_unique(array_map('intval', $game)));
        sort($game);

        return $game;
    }
}