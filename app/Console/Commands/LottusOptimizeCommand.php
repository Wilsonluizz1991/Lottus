<?php

namespace App\Console\Commands;

use App\Services\Lottus\Optimization\ParameterOptimizerService;
use Illuminate\Console\Command;

class LottusOptimizeCommand extends Command
{
    protected $signature = 'lottus:optimize
                            {inicio : Concurso inicial}
                            {fim : Concurso final}
                            {--jogos=5 : Quantidade de jogos por concurso}
                            {--tests=12 : Quantidade máxima de combinações a testar}';

    protected $description = 'Otimiza os pesos do motor Lottus usando backtest controlado';

    public function handle(ParameterOptimizerService $optimizer): int
    {
        $inicio = (int) $this->argument('inicio');
        $fim = (int) $this->argument('fim');
        $jogos = (int) $this->option('jogos');
        $tests = (int) $this->option('tests');

        $this->info('Iniciando otimização controlada...');
        $this->line("Intervalo: {$inicio} até {$fim}");
        $this->line("Jogos por concurso: {$jogos}");
        $this->line("Máximo de testes: {$tests}");
        $this->newLine();

        try {
            $startedAt = microtime(true);

            $resultado = $optimizer->optimize(
                $inicio,
                $fim,
                $jogos,
                $tests,
                function (array $test, array $best, int $total) {
                    $this->line("Teste #{$test['test_number']}/{$total}");
                    $this->line('Parâmetros: ' . json_encode($test['params'], JSON_UNESCAPED_UNICODE));
                    $this->line("Duração: {$test['duration_seconds']}s");
                    $this->line("Score: {$test['score']}");
                    $this->line('Faixas => 11: ' . $test['result']['faixas'][11] . ' | 12: ' . $test['result']['faixas'][12] . ' | 13: ' . $test['result']['faixas'][13]);
                    $this->line('Melhor até agora: Score ' . $best['score'] . ' com ' . json_encode($best['params'], JSON_UNESCAPED_UNICODE));
                    $this->newLine();
                }
            );

            $duration = round(microtime(true) - $startedAt, 2);
            $best = $resultado['best'];

            $this->info('Otimização concluída.');
            $this->line("Tempo total: {$duration}s");
            $this->newLine();

            $this->table(
                ['Parâmetro', 'Valor'],
                [
                    ['frequency', $best['params']['frequency']],
                    ['delay', $best['params']['delay']],
                    ['correlation', $best['params']['correlation']],
                    ['cycle', $best['params']['cycle']],
                    ['score', $best['score']],
                    ['faixa 11', $best['result']['faixas'][11]],
                    ['faixa 12', $best['result']['faixas'][12]],
                    ['faixa 13', $best['result']['faixas'][13]],
                    ['duração do melhor teste (s)', $best['duration_seconds']],
                ]
            );

            $this->newLine();
            $this->info('Comando sugerido para validação completa:');
            $this->line(
                'php artisan lottus:backtest ' . $inicio . ' ' . $fim . ' --jogos=' . $jogos
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Erro ao executar otimização: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}