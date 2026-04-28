<?php

namespace App\Console\Commands;

use App\Services\Lottus\Backtest\BacktestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class LottusTunePortfolioCommand extends Command
{
    protected $signature = 'lottus:tune-portfolio
                            {inicio : Concurso inicial}
                            {fim : Concurso final}
                            {--jogos=5 : Quantidade de jogos por concurso}
                            {--cenarios=50 : Quantidade de cenários aleatórios para testar}
                            {--preset=default : Preset base de tuning: default, hunt_14_plus ou balanced_elite}';

    protected $description = 'Executa tuning automático dos parâmetros do PortfolioOptimizerService.';

    public function handle(BacktestService $backtestService): int
    {
        $runtimeConfig = config('lottus.runtime', []);

        if (! empty($runtimeConfig['memory_limit'])) {
            ini_set('memory_limit', $runtimeConfig['memory_limit']);
        }

        if (($runtimeConfig['gc_enabled'] ?? false) === true) {
            gc_enable();
        }

        $inicio = (int) $this->argument('inicio');
        $fim = (int) $this->argument('fim');
        $jogos = (int) $this->option('jogos');
        $cenarios = (int) $this->option('cenarios');
        $preset = (string) $this->option('preset');

        $defaultTuning = $this->resolvePreset($preset);
        $searchSpace = config('lottus_portfolio_tuning.search_space', []);

        $this->info('Iniciando tuning automático do Portfolio...');
        $this->line("Intervalo: {$inicio} até {$fim}");
        $this->line("Jogos por concurso: {$jogos}");
        $this->line("Cenários: {$cenarios}");
        $this->line("Preset: {$preset}");
        $this->line('Memory Limit: ' . ini_get('memory_limit'));
        $this->newLine();

        $ranking = [];

        $storagePath = storage_path('app/lottus');
        $jsonFile = $storagePath . '/tuning-results.json';

        if (! File::exists($storagePath)) {
            File::makeDirectory($storagePath, 0755, true);
        }

        $persistedRanking = [];

        if (File::exists($jsonFile)) {
            $persistedRanking = json_decode(File::get($jsonFile), true) ?? [];
        }

        if (empty($defaultTuning) || empty($searchSpace)) {
            $this->error('Config lottus_portfolio_tuning não encontrado, incompleto ou preset inválido.');

            return self::FAILURE;
        }

        for ($i = 1; $i <= $cenarios; $i++) {
            $scenario = null;
            $resultado = null;

            try {
                $scenario = $this->buildRandomScenario($defaultTuning, $searchSpace);

                $this->line("Testando cenário {$i}/{$cenarios}...");

                $resultado = $backtestService->run(
                    $inicio,
                    $fim,
                    $jogos,
                    $scenario
                );

                $score = $this->scoreScenario($resultado);
                $lossMedio = $this->averageLoss($resultado);

                $ranking[] = [
                    'preset' => $preset,
                    'scenario' => $scenario,
                    'resultado' => $resultado,
                    'score' => $score,
                ];

                $persistedRanking[] = [
                    'timestamp' => now()->toDateTimeString(),
                    'preset' => $preset,
                    'inicio' => $inicio,
                    'fim' => $fim,
                    'jogos' => $jogos,
                    'score' => $score,
                    'faixas' => $resultado['faixas'] ?? [],
                    'melhor_resultado' => $resultado['melhor_resultado'] ?? [],
                    'loss_medio' => $lossMedio,
                    'scenario' => $scenario,
                ];

                usort($ranking, fn ($a, $b) => $b['score'] <=> $a['score']);

                if (count($ranking) > 30) {
                    $ranking = array_slice($ranking, 0, 30);
                }

                usort($persistedRanking, fn ($a, $b) => $b['score'] <=> $a['score']);

                if (count($persistedRanking) > 500) {
                    $persistedRanking = array_slice($persistedRanking, 0, 500);
                }

                File::put(
                    $jsonFile,
                    json_encode($persistedRanking, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );

                $this->line(
                    'Score: ' . $score .
                    ' | 11: ' . ($resultado['faixas'][11] ?? 0) .
                    ' | 12: ' . ($resultado['faixas'][12] ?? 0) .
                    ' | 13: ' . ($resultado['faixas'][13] ?? 0) .
                    ' | 14: ' . ($resultado['faixas'][14] ?? 0) .
                    ' | 15: ' . ($resultado['faixas'][15] ?? 0) .
                    ' | Melhor: ' . ($resultado['melhor_resultado']['acertos'] ?? 0) .
                    ' | Loss médio: ' . $lossMedio
                );

                if ($i % 25 === 0) {
                    $this->line(
                        'Memória atual: ' .
                        round(memory_get_usage(true) / 1024 / 1024, 2) .
                        ' MB'
                    );
                }
            } catch (\Throwable $e) {
                $this->warn("Cenário {$i} falhou: " . $e->getMessage());
            }

            unset($resultado);
            unset($scenario);

            gc_collect_cycles();
        }

        if (empty($ranking)) {
            $this->error('Nenhum cenário foi executado com sucesso.');

            return self::FAILURE;
        }

        usort($ranking, fn ($a, $b) => $b['score'] <=> $a['score']);

        $this->newLine();
        $this->info('Top 10 cenários encontrados nesta execução:');

        $rows = [];

        foreach (array_slice($ranking, 0, 10) as $index => $item) {
            $resultado = $item['resultado'];

            $rows[] = [
                '#' => $index + 1,
                'Preset' => $item['preset'] ?? $preset,
                'Score' => $item['score'],
                '11' => $resultado['faixas'][11] ?? 0,
                '12' => $resultado['faixas'][12] ?? 0,
                '13' => $resultado['faixas'][13] ?? 0,
                '14' => $resultado['faixas'][14] ?? 0,
                '15' => $resultado['faixas'][15] ?? 0,
                'Melhor' => $resultado['melhor_resultado']['acertos'] ?? 0,
                'Loss médio' => $this->averageLoss($resultado),
            ];
        }

        $this->table(
            ['#', 'Preset', 'Score', '11', '12', '13', '14', '15', 'Melhor', 'Loss médio'],
            $rows
        );

        $best = $ranking[0];

        $this->newLine();
        $this->info('Melhor cenário completo desta execução:');
        $this->line(var_export($best['scenario'], true));

        $this->newLine();
        $this->info('Resumo do melhor cenário desta execução:');

        $resultado = $best['resultado'];

        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Preset', $best['preset'] ?? $preset],
                ['Score', $best['score']],
                ['Concursos testados', $resultado['concursos_testados'] ?? 0],
                ['Jogos gerados', $resultado['jogos_gerados'] ?? 0],
                ['Faixa 11', $resultado['faixas'][11] ?? 0],
                ['Faixa 12', $resultado['faixas'][12] ?? 0],
                ['Faixa 13', $resultado['faixas'][13] ?? 0],
                ['Faixa 14', $resultado['faixas'][14] ?? 0],
                ['Faixa 15', $resultado['faixas'][15] ?? 0],
                ['Melhor concurso', $resultado['melhor_resultado']['concurso'] ?? '-'],
                ['Melhor acerto', $resultado['melhor_resultado']['acertos'] ?? 0],
                ['Loss médio', $this->averageLoss($resultado)],
                ['Arquivo salvo', $jsonFile],
            ]
        );

        return self::SUCCESS;
    }

    protected function resolvePreset(string $preset): array
    {
        if ($preset === 'default') {
            return config('lottus_portfolio_tuning.default', []);
        }

        return config("lottus_portfolio_tuning.presets.{$preset}", []);
    }

    protected function buildRandomScenario(array $defaultTuning, array $searchSpace): array
    {
        $scenario = $defaultTuning;

        foreach ($searchSpace as $path => $values) {
            if (empty($values)) {
                continue;
            }

            $value = $values[array_rand($values)];

            $this->setNestedValue($scenario, $path, $value);
        }

        return $scenario;
    }

    protected function setNestedValue(array &$array, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $current = &$array;

        $lastIndex = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if ($index === $lastIndex) {
                $current[$segment] = $value;

                return;
            }

            if (! isset($current[$segment]) || ! is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }
    }

    protected function scoreScenario(array $resultado): float
    {
        $scoring = config('lottus_portfolio_tuning.scoring', []);

        $faixas = $resultado['faixas'] ?? [];
        $diagnostico = $resultado['diagnostico'] ?? [];

        $score = 0.0;

        $score += ($faixas[15] ?? 0) * (float) ($scoring['faixa_15'] ?? 1000000);
        $score += ($faixas[14] ?? 0) * (float) ($scoring['faixa_14'] ?? 250000);
        $score += ($faixas[13] ?? 0) * (float) ($scoring['faixa_13'] ?? 25000);
        $score += ($faixas[12] ?? 0) * (float) ($scoring['faixa_12'] ?? 1500);
        $score += ($faixas[11] ?? 0) * (float) ($scoring['faixa_11'] ?? 100);

        foreach ($diagnostico as $item) {
            $raw = (int) ($item['raw'] ?? 0);
            $selected = (int) ($item['selected'] ?? 0);
            $loss = max(0, (int) ($item['loss'] ?? 0));

            if ($raw >= 14 && $selected >= 14) {
                $score += (float) ($scoring['raw_14_preserved_bonus'] ?? 50000);
            }

            if ($raw >= 13 && $selected >= 13) {
                $score += (float) ($scoring['raw_13_preserved_bonus'] ?? 10000);
            }

            $score -= $loss * (float) ($scoring['loss_penalty'] ?? 750);
        }

        return round($score, 4);
    }

    protected function averageLoss(array $resultado): float
    {
        $diagnostico = $resultado['diagnostico'] ?? [];

        if (empty($diagnostico)) {
            return 0.0;
        }

        $total = 0;
        $count = 0;

        foreach ($diagnostico as $item) {
            $total += max(0, (int) ($item['loss'] ?? 0));
            $count++;
        }

        if ($count === 0) {
            return 0.0;
        }

        return round($total / $count, 4);
    }
}