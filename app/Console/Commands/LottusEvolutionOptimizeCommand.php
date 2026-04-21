<?php

namespace App\Console\Commands;

use App\Services\Lottus\Optimization\EvolutionOptimizerService;
use Illuminate\Console\Command;

class LottusEvolutionOptimizeCommand extends Command
{
    protected $signature = 'lottus:evolve
                            {inicio : Concurso inicial}
                            {fim : Concurso final}
                            {--jogos=5 : Quantidade de jogos por concurso}
                            {--population=8 : Tamanho da população}
                            {--generations=4 : Quantidade de gerações}';

    protected $description = 'Executa evolução de parâmetros do motor Lottus usando seleção, cruzamento e mutação';

    public function handle(EvolutionOptimizerService $optimizer): int
    {
        $inicio = (int) $this->argument('inicio');
        $fim = (int) $this->argument('fim');
        $jogos = (int) $this->option('jogos');
        $population = (int) $this->option('population');
        $generations = (int) $this->option('generations');

        $this->info('Iniciando evolução do motor Lottus...');
        $this->line("Intervalo: {$inicio} até {$fim}");
        $this->line("Jogos por concurso: {$jogos}");
        $this->line("População: {$population}");
        $this->line("Gerações: {$generations}");
        $this->newLine();

        try {
            $startedAt = microtime(true);

            $resultado = $optimizer->optimize(
                $inicio,
                $fim,
                $jogos,
                $population,
                $generations,
                function (array $individual, array $globalBest, int $generation, int $generations) {
                    $this->line("Geração {$generation}/{$generations} | Indivíduo {$individual['individual']}");
                    $this->line('Parâmetros: ' . json_encode($individual['params'], JSON_UNESCAPED_UNICODE));
                    $this->line("Fitness: {$individual['fitness']}");
                    $this->line('Faixas => 11: ' . $individual['result']['faixas'][11] . ' | 12: ' . $individual['result']['faixas'][12] . ' | 13: ' . $individual['result']['faixas'][13] . ' | 14: ' . $individual['result']['faixas'][14] . ' | 15: ' . $individual['result']['faixas'][15]);
                    $this->line("Duração: {$individual['duration_seconds']}s");
                    $this->line('Melhor global até agora: Fitness ' . $globalBest['fitness'] . ' com ' . json_encode($globalBest['params'], JSON_UNESCAPED_UNICODE));
                    $this->newLine();
                }
            );

            $duration = round(microtime(true) - $startedAt, 2);
            $best = $resultado['best'];

            $this->info('Evolução concluída.');
            $this->line("Tempo total: {$duration}s");
            $this->newLine();

            $this->table(
                ['Parâmetro', 'Valor'],
                [
                    ['frequency', $best['params']['frequency']],
                    ['delay', $best['params']['delay']],
                    ['correlation', $best['params']['correlation']],
                    ['cycle', $best['params']['cycle']],
                    ['fitness', $best['fitness']],
                    ['faixa 11', $best['result']['faixas'][11]],
                    ['faixa 12', $best['result']['faixas'][12]],
                    ['faixa 13', $best['result']['faixas'][13]],
                    ['faixa 14', $best['result']['faixas'][14]],
                    ['faixa 15', $best['result']['faixas'][15]],
                    ['geração', $best['generation']],
                    ['indivíduo', $best['individual']],
                    ['duração do melhor teste (s)', $best['duration_seconds']],
                ]
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Erro ao executar evolução: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}