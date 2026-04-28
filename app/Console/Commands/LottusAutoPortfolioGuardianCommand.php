<?php

namespace App\Console\Commands;

use App\Services\Lottus\Backtest\BacktestService;
use App\Services\Lottus\Tuning\PortfolioGuardianEvaluator;
use App\Services\Lottus\Tuning\PortfolioGuardianScenarioFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

class LottusAutoPortfolioGuardianCommand extends Command
{
    protected $signature = 'lottus:portfolio-guardian
                            {inicio : Concurso inicial}
                            {fim : Concurso final}
                            {--jogos=5 : Quantidade de jogos por concurso}
                            {--cenarios=100 : Quantidade máxima de cenários}
                            {--salvar=1 : Salvar melhor cenário em storage/app/lottus/portfolio-guardian}';

    protected $description = 'Executa tuning automático do Portfolio até reduzir LOSS RAW vs SELECTED e preservar jogos elite.';

    public function handle(
        BacktestService $backtestService,
        PortfolioGuardianScenarioFactory $factory,
        PortfolioGuardianEvaluator $evaluator
    ): int {
        $inicio = (int) $this->argument('inicio');
        $fim = (int) $this->argument('fim');
        $jogos = (int) $this->option('jogos');
        $cenarios = (int) $this->option('cenarios');
        $salvar = (bool) $this->option('salvar');

        $originalDefault = config('lottus_portfolio_tuning.default', []);

        $best = null;
        $history = [];

        $this->info('Iniciando Portfolio Guardian...');
        $this->line("Intervalo: {$inicio} até {$fim}");
        $this->line("Jogos por concurso: {$jogos}");
        $this->line("Cenários máximos: {$cenarios}");
        $this->newLine();

        try {
            for ($i = 1; $i <= $cenarios; $i++) {
                $scenario = $factory->makeScenario($originalDefault);

                Config::set('lottus_portfolio_tuning.default', $scenario);

                $this->line("Testando cenário {$i}/{$cenarios}...");

                $resultado = $backtestService->run($inicio, $fim, $jogos);
                $metrics = $evaluator->evaluate($resultado);

                $row = [
                    'cenario' => $i,
                    'score' => $metrics['score'],
                    'selected_11' => $metrics['selected_11'],
                    'selected_12' => $metrics['selected_12'],
                    'selected_13' => $metrics['selected_13'],
                    'selected_14' => $metrics['selected_14'],
                    'selected_15' => $metrics['selected_15'],
                    'raw_13' => $metrics['raw_13'],
                    'raw_14' => $metrics['raw_14'],
                    'raw_13_lost' => $metrics['raw_13_lost'],
                    'raw_14_lost' => $metrics['raw_14_lost'],
                    'average_loss' => $metrics['average_loss'],
                    'melhor_acerto' => $metrics['melhor_acerto'],
                    'melhor_concurso' => $metrics['melhor_concurso'],
                    'scenario' => $scenario,
                ];

                $history[] = $row;

                if ($best === null || $metrics['score'] > $best['score']) {
                    $best = $row;
                }

                $this->line(
                    'Score: ' . $metrics['score']
                    . ' | 11: ' . $metrics['selected_11']
                    . ' | 12: ' . $metrics['selected_12']
                    . ' | 13: ' . $metrics['selected_13']
                    . ' | 14: ' . $metrics['selected_14']
                    . ' | 15: ' . $metrics['selected_15']
                    . ' | RAW13 lost: ' . $metrics['raw_13_lost']
                    . ' | RAW14 lost: ' . $metrics['raw_14_lost']
                    . ' | Loss médio: ' . $metrics['average_loss']
                    . ' | Melhor: ' . $metrics['melhor_acerto']
                );

                if ($evaluator->shouldStop($metrics)) {
                    $this->newLine();
                    $this->info('Critério de parada atingido. Portfolio candidato encontrado.');
                    break;
                }
            }

            Config::set('lottus_portfolio_tuning.default', $originalDefault);

            $this->newLine();

            if ($best === null) {
                $this->error('Nenhum cenário foi avaliado.');

                return self::FAILURE;
            }

            usort($history, fn ($a, $b) => $b['score'] <=> $a['score']);

            $this->info('Top 10 cenários encontrados:');

            $this->table(
                [
                    '#',
                    'Score',
                    '11',
                    '12',
                    '13',
                    '14',
                    '15',
                    'RAW13 lost',
                    'RAW14 lost',
                    'Loss médio',
                    'Melhor',
                ],
                array_map(
                    fn ($item, $index) => [
                        $index + 1,
                        $item['score'],
                        $item['selected_11'],
                        $item['selected_12'],
                        $item['selected_13'],
                        $item['selected_14'],
                        $item['selected_15'],
                        $item['raw_13_lost'],
                        $item['raw_14_lost'],
                        $item['average_loss'],
                        $item['melhor_acerto'],
                    ],
                    array_slice($history, 0, 10),
                    array_keys(array_slice($history, 0, 10))
                )
            );

            $this->newLine();
            $this->info('Melhor cenário completo:');
            $this->line(var_export($best['scenario'], true));

            if ($salvar) {
                $this->saveBestScenario($best, $history, $inicio, $fim, $jogos);
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Config::set('lottus_portfolio_tuning.default', $originalDefault);

            $this->error('Erro ao executar Portfolio Guardian: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    protected function saveBestScenario(array $best, array $history, int $inicio, int $fim, int $jogos): void
    {
        $directory = 'lottus/portfolio-guardian';
        $timestamp = now()->format('Ymd_His');

        Storage::put(
            "{$directory}/best_{$inicio}_{$fim}_{$jogos}_{$timestamp}.json",
            json_encode($best, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        Storage::put(
            "{$directory}/history_{$inicio}_{$fim}_{$jogos}_{$timestamp}.json",
            json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        Storage::put(
            "{$directory}/best_{$inicio}_{$fim}_{$jogos}_{$timestamp}.php",
            "<?php\n\nreturn " . var_export($best['scenario'], true) . ";\n"
        );

        $this->newLine();
        $this->info("Melhor cenário salvo em storage/app/{$directory}");
    }
}