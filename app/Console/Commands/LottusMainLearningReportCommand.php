<?php

namespace App\Console\Commands;

use App\Services\Lottus\MainLearning\LottusMainLearningEvaluationService;
use Illuminate\Console\Command;

class LottusMainLearningReportCommand extends Command
{
    protected $signature = 'lottus:main-learning-report
        {inicio : Concurso inicial/base}
        {fim : Concurso final}
        {--jogos=10 : Quantidade de jogos por concurso}';

    protected $description = 'Mostra relatorio A/B do aprendizado do motor principal.';

    public function handle(LottusMainLearningEvaluationService $evaluationService): int
    {
        $inicio = (int) $this->argument('inicio');
        $fim = (int) $this->argument('fim');
        $jogos = (int) $this->option('jogos');

        $this->info("Rodando A/B portfolio-only: {$inicio} ate {$fim}, jogos={$jogos}");
        $result = $evaluationService->comparePortfolioCalibration($inicio, $fim, $jogos);

        $this->line('Baseline');
        $this->table(
            ['Metrica', 'Valor'],
            collect($result['baseline_metrics'])
                ->map(fn ($value, $key) => [$key, $value])
                ->values()
                ->all()
        );

        $this->line('Aprendizado');
        $this->table(
            ['Metrica', 'Valor'],
            collect($result['learned_metrics'])
                ->map(fn ($value, $key) => [$key, $value])
                ->values()
                ->all()
        );

        $this->line('Delta');
        $this->table(
            ['Metrica', 'Delta'],
            collect($result['delta'])
                ->map(fn ($value, $key) => [$key, $value])
                ->values()
                ->all()
        );

        $diagnostics = collect($result['learned_summary']['diagnostico'] ?? [])
            ->filter(fn (array $row): bool => (int) ($row['raw'] ?? 0) >= 14 || (int) ($row['loss'] ?? 0) > 0)
            ->map(fn (array $row): array => [
                'Concurso' => $row['concurso'] ?? '-',
                'RAW' => $row['raw'] ?? 0,
                'SELECTED' => $row['selected'] ?? 0,
                'LOSS' => $row['loss'] ?? 0,
                'RAW Rank' => $row['raw_rank'] ?? '-',
                'RAW Strategy' => $row['raw_strategy'] ?? '-',
                'SEL Ranks' => implode(', ', $row['selected_ranks'] ?? []),
                'RAW no SELECTED?' => ! empty($row['raw_no_selected']) ? 'SIM' : 'NAO',
            ])
            ->values()
            ->all();

        if (! empty($diagnostics)) {
            $this->line('Diagnostico aprendizado');
            $this->table(
                ['Concurso', 'RAW', 'SELECTED', 'LOSS', 'RAW Rank', 'RAW Strategy', 'SEL Ranks', 'RAW no SELECTED?'],
                $diagnostics
            );
        }

        return self::SUCCESS;
    }
}
