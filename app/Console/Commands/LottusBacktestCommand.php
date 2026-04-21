<?php

namespace App\Console\Commands;

use App\Services\Lottus\Backtest\BacktestService;
use Illuminate\Console\Command;

class LottusBacktestCommand extends Command
{
    protected $signature = 'lottus:backtest 
                            {inicio : Concurso inicial}
                            {fim : Concurso final}
                            {--jogos=1 : Quantidade de jogos por concurso}';

    protected $description = 'Executa backtest do motor estatístico da Lottus em um intervalo de concursos.';

    public function handle(BacktestService $backtestService): int
    {
        $inicio = (int) $this->argument('inicio');
        $fim = (int) $this->argument('fim');
        $jogos = (int) $this->option('jogos');

        $this->info('Iniciando backtest...');
        $this->line("Intervalo: {$inicio} até {$fim}");
        $this->line("Jogos por concurso: {$jogos}");
        $this->newLine();

        try {
            $resultado = $backtestService->run($inicio, $fim, $jogos);

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
                    ['Taxa 11 (%)', $resultado['taxas'][11]],
                    ['Taxa 12 (%)', $resultado['taxas'][12]],
                    ['Taxa 13 (%)', $resultado['taxas'][13]],
                    ['Taxa 14 (%)', $resultado['taxas'][14]],
                    ['Taxa 15 (%)', $resultado['taxas'][15]],
                ]
            );

            $this->newLine();
            $this->info('Melhor resultado encontrado:');
            $this->line('Concurso: ' . ($resultado['melhor_resultado']['concurso'] ?? '-'));
            $this->line('Acertos: ' . ($resultado['melhor_resultado']['acertos'] ?? 0));
            $this->line('Jogo: ' . implode(', ', $resultado['melhor_resultado']['jogo'] ?? []));
            $this->line('Resultado: ' . implode(', ', $resultado['melhor_resultado']['resultado'] ?? []));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Erro ao executar backtest: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}