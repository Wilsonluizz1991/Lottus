<?php

namespace App\Console\Commands;

use App\Services\Lottus\Backtest\BacktestService;
use Illuminate\Console\Command;

class LottusBacktestCommand extends Command
{
    protected $signature = 'lottus:backtest 
                            {inicio : Concurso inicial}
                            {fim : Concurso final}
                            {--jogos=1 : Quantidade de jogos por concurso}
                            {--seed= : Seed opcional para backtests reproduziveis}';

    protected $description = 'Executa backtest do motor estatístico da Lottus em um intervalo de concursos.';

    public function handle(BacktestService $backtestService): int
    {
        $inicio = (int) $this->argument('inicio');
        $fim = (int) $this->argument('fim');
        $jogos = (int) $this->option('jogos');
        $seedOption = $this->option('seed');
        $seed = $seedOption !== null && $seedOption !== '' ? (int) $seedOption : null;

        $this->info('Iniciando backtest...');
        $this->line("Intervalo: {$inicio} até {$fim}");
        $this->line("Jogos por concurso: {$jogos}");
        $this->line('Seed: ' . ($seed ?? 'aleatoria'));
        $this->newLine();

        try {
            $resultado = $backtestService->run($inicio, $fim, $jogos, null, $seed);

            $this->info('Backtest concluído com sucesso.');
            $this->newLine();

            $this->table(
                ['Métrica', 'Valor'],
                [
                    ['Concursos testados', $resultado['concursos_testados']],
                    ['Jogos gerados', $resultado['jogos_gerados']],
                    ['Faixa 11', $resultado['faixas'][11]],
                    ['Faixa 12', $resultado['faixas'][12]],
                    ['Faixa 13', $resultado['faixas'][13]],
                    ['Faixa 14', $resultado['faixas'][14]],
                    ['Faixa 15', $resultado['faixas'][15]],
                    ['RAW melhor 11', $resultado['raw_melhor_faixas'][11] ?? 0],
                    ['RAW melhor 12', $resultado['raw_melhor_faixas'][12] ?? 0],
                    ['RAW melhor 13', $resultado['raw_melhor_faixas'][13] ?? 0],
                    ['RAW melhor 14', $resultado['raw_melhor_faixas'][14] ?? 0],
                    ['RAW melhor 15', $resultado['raw_melhor_faixas'][15] ?? 0],
                    ['RAW 14/15 total', $resultado['raw_14_15_total'] ?? 0],
                    ['RAW 14/15 preservados', $resultado['raw_14_15_preservados'] ?? 0],
                    ['RAW 14/15 loss', $resultado['raw_14_15_loss'] ?? 0],
                    ['RAW near-15 candidatos', $resultado['near_15_raw_candidates'] ?? 0],
                    ['RAW 15 candidatos', $resultado['raw_15_candidates'] ?? 0],
                    ['Taxa 11 (%)', $resultado['taxas'][11]],
                    ['Taxa 12 (%)', $resultado['taxas'][12]],
                    ['Taxa 13 (%)', $resultado['taxas'][13]],
                    ['Taxa 14 (%)', $resultado['taxas'][14]],
                    ['Taxa 15 (%)', $resultado['taxas'][15]],
                    ['Taxa 13+ (%)', $resultado['taxas']['13_plus'] ?? 0],
                    ['Taxa 14+ (%)', $resultado['taxas']['14_plus'] ?? 0],
                ]
            );

            if (! empty($resultado['strategy_stats'])) {
                $this->newLine();
                $this->info('Estrategias RAW:');

                $linhasEstrategia = [];

                foreach ($resultado['strategy_stats'] as $strategy => $stats) {
                    $linhasEstrategia[] = [
                        'Estrategia' => $strategy,
                        'Candidatos' => $stats['candidates'] ?? 0,
                        'Best' => $stats['best_hits'] ?? 0,
                        'RAW 13' => $stats['raw_13'] ?? 0,
                        'RAW 14' => $stats['raw_14'] ?? 0,
                        'RAW 15' => $stats['raw_15'] ?? 0,
                    ];
                }

                usort($linhasEstrategia, function (array $a, array $b): int {
                    return (($b['RAW 15'] * 1000000) + ($b['RAW 14'] * 10000) + ($b['RAW 13'] * 100) + $b['Best'])
                        <=>
                        (($a['RAW 15'] * 1000000) + ($a['RAW 14'] * 10000) + ($a['RAW 13'] * 100) + $a['Best']);
                });

                $this->table(
                    ['Estrategia', 'Candidatos', 'Best', 'RAW 13', 'RAW 14', 'RAW 15'],
                    $linhasEstrategia
                );
            }

            $this->newLine();
            $this->info('Melhor resultado encontrado:');
            $this->line('Concurso: ' . ($resultado['melhor_resultado']['concurso'] ?? '-'));
            $this->line('Acertos: ' . ($resultado['melhor_resultado']['acertos'] ?? 0));
            $this->line('Jogo: ' . implode(', ', $resultado['melhor_resultado']['jogo'] ?? []));
            $this->line('Resultado: ' . implode(', ', $resultado['melhor_resultado']['resultado'] ?? []));

            if (! empty($resultado['diagnostico'])) {
                $this->newLine();
                $this->info('Diagnóstico RAW vs SELECTED (somente concursos relevantes):');

                $linhasDiagnostico = [];

                foreach ($resultado['diagnostico'] as $item) {
                    if (($item['raw'] ?? 0) >= 14 || ($item['loss'] ?? 0) > 0) {
                        $linhasDiagnostico[] = [
                            'Concurso' => $item['concurso'],
                            'RAW' => $item['raw'],
                            'SELECTED' => $item['selected'],
                            'LOSS' => $item['loss'],
                            'RAW no SELECTED?' => ! empty($item['raw_no_selected']) ? 'SIM' : 'NÃO',
                            'RAW Strategy' => $item['raw_strategy'] ?? '-',
                            'SEL Strategy' => $item['selected_strategy'] ?? '-',
                            'RAW Rank' => $item['raw_rank'] ?? '-',
                            'RAW Score' => $item['raw_score'] ?? '-',
                            'RAW HistMax' => $item['raw_historical_max_hits'] ?? '-',
                            'RAW Hist14+' => $item['raw_historical_14_plus'] ?? '-',
                            'RAW Faltantes' => implode(', ', $item['raw_missing_numbers'] ?? []),
                            'RAW jogo' => implode(', ', $item['raw_jogo'] ?? []),
                            'SELECTED jogo' => implode(', ', $item['selected_jogo'] ?? []),
                            'Resultado' => implode(', ', $item['resultado'] ?? []),
                        ];
                    }
                }

                if (empty($linhasDiagnostico)) {
                    $this->line('Nenhum concurso com RAW >= 14 ou LOSS > 0 neste intervalo.');
                } else {
                    $this->table(
                        ['Concurso', 'RAW', 'SELECTED', 'LOSS', 'RAW no SELECTED?', 'RAW Strategy', 'SEL Strategy', 'RAW Rank', 'RAW Score', 'RAW HistMax', 'RAW Hist14+', 'RAW Faltantes', 'RAW jogo', 'SELECTED jogo', 'Resultado'],
                        $linhasDiagnostico
                    );
                }
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Erro ao executar backtest: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
