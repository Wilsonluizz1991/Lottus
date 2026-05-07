<?php

namespace App\Console\Commands;

use App\Services\Lottus\MainLearning\LottusMainAdaptiveLearningService;
use Illuminate\Console\Command;

class LottusMainLearningReprocessCommand extends Command
{
    protected $signature = 'lottus:main-learning-reprocess
        {concurso : Concurso que alimentara a calibracao do proximo}
        {--validate-only : Executa A/B sem gravar snapshot}';

    protected $description = 'Reprocessa manualmente a calibracao adaptativa do motor principal.';

    public function handle(LottusMainAdaptiveLearningService $learningService): int
    {
        $concurso = (int) $this->argument('concurso');
        $validateOnly = (bool) $this->option('validate-only');

        $run = $learningService->enqueue($concurso, true);

        if (! $run) {
            $this->warn('Aprendizado principal desativado em config/lottus_main_learning.php.');

            return self::SUCCESS;
        }

        $this->info('Processando aprendizado principal...');
        $processed = $learningService->processRun($run->id, $validateOnly);

        $this->table(
            ['Campo', 'Valor'],
            [
                ['Run', $processed->id],
                ['Concurso', $processed->concurso],
                ['Status', $processed->status],
                ['Decisao', $processed->decision],
                ['Duracao ms', $processed->duration_ms],
            ]
        );

        if ($processed->delta_metrics_json) {
            $this->line('Delta principal:');
            $this->table(
                ['Metrica', 'Delta'],
                collect($processed->delta_metrics_json)
                    ->map(fn ($value, $key) => [$key, is_scalar($value) ? $value : json_encode($value)])
                    ->values()
                    ->all()
            );
        }

        return self::SUCCESS;
    }
}
