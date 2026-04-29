<?php

namespace App\Console\Commands;

use App\Models\LotofacilConcurso;
use App\Services\Lottus\Analysis\CorrelationAnalysisService;
use App\Services\Lottus\Analysis\CycleAnalysisService;
use App\Services\Lottus\Analysis\DelayAnalysisService;
use App\Services\Lottus\Analysis\FrequencyAnalysisService;
use App\Services\Lottus\Analysis\StructureAnalysisService;
use App\Services\Lottus\Data\HistoricalDataService;
use App\Services\Lottus\Fechamento\FechamentoCandidateSelector;
use App\Services\Lottus\Fechamento\FechamentoCombinationGenerator;
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
                            {--diagnostico=1 : Mostrar RAW vs SELECTED}';

    protected $description = 'Backtest do Fechamento Inteligente com foco total em 14+';

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
        protected FechamentoReducer $reducer
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $inicio = (int) $this->argument('inicio');
        $fim = (int) $this->argument('fim');
        $quantidadeDezenas = (int) $this->option('dezenas');
        $diagnostico = (bool) ((int) $this->option('diagnostico'));

        if ($quantidadeDezenas < 16 || $quantidadeDezenas > 20) {
            $this->error('Quantidade de dezenas deve ser entre 16 e 20.');
            return self::FAILURE;
        }

        $quantidadeJogos = $this->resolveQuantidadeJogos($quantidadeDezenas);

        $this->info('Iniciando backtest do fechamento...');
        $this->line("Intervalo: {$inicio} até {$fim}");
        $this->line("Fechamento: {$quantidadeDezenas} dezenas");
        $this->line("Jogos finais: {$quantidadeJogos}");
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

        $best = [
            'acertos' => 0,
            'concurso' => null,
            'jogo' => [],
            'resultado' => [],
            'tipo' => null,
        ];

        $diagnosticos = [];

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

                $dezenasBase = $this->candidateSelector->select(
                    $quantidadeDezenas,
                    $frequency,
                    $delay,
                    $correlation,
                    $structure,
                    $cycle,
                    $concursoBase
                );

                $combinations = $this->combinationGenerator->generate(
                    $dezenasBase,
                    $quantidadeDezenas
                );

                $scored = $this->scoreService->score(
                    $combinations,
                    $frequency,
                    $delay,
                    $correlation,
                    $structure,
                    $cycle,
                    $concursoBase
                );

                $selected = $this->reducer->reduce(
                    $scored,
                    $quantidadeJogos,
                    $dezenasBase
                );

                if (empty($selected)) {
                    continue;
                }

                $stats['concursos']++;
                $stats['jogos'] += count($selected);

                $rawBest = $this->bestHit($scored, $resultadoReal);
                $selectedBest = $this->bestHit($selected, $resultadoReal);

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
                    ];
                }

                if ($diagnostico && ($loss > 0 || $rawBest['acertos'] >= 14)) {
                    $diagnosticos[] = [
                        $concurso,
                        $rawBest['acertos'],
                        $selectedBest['acertos'],
                        $loss,
                        implode(', ', $dezenasBase),
                    ];
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
        }

        if ($diagnostico && ! empty($diagnosticos)) {
            $this->newLine();
            $this->info('Diagnóstico RAW vs SELECTED');

            $this->table(
                ['Concurso', 'RAW', 'SELECTED', 'LOSS', 'Dezenas Base'],
                $diagnosticos
            );
        }

        return self::SUCCESS;
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
}