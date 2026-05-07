<?php

namespace App\Console\Commands;

use App\Services\Lottus\MainLearning\LottusMainAdaptiveLearningService;
use Illuminate\Console\Command;

class LottusMainLearningTrainCommand extends Command
{
    protected $signature = 'lottus:main-learning-train
        {inicio : Primeiro concurso a reprocessar}
        {fim : Ultimo concurso a reprocessar}
        {--validate-only : Executa A/B sem gravar snapshots}';

    protected $description = 'Treina/reprocessa aprendizado principal em uma faixa de concursos.';

    public function handle(LottusMainAdaptiveLearningService $learningService): int
    {
        $inicio = (int) $this->argument('inicio');
        $fim = (int) $this->argument('fim');
        $validateOnly = (bool) $this->option('validate-only');

        if ($inicio > $fim) {
            $this->error('Inicio deve ser menor ou igual ao fim.');

            return self::FAILURE;
        }

        $bar = $this->output->createProgressBar(($fim - $inicio) + 1);
        $bar->start();

        for ($concurso = $inicio; $concurso <= $fim; $concurso++) {
            $run = $learningService->enqueue($concurso, true);

            if ($run) {
                $learningService->processRun($run->id, $validateOnly);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Treinamento concluido.');

        return self::SUCCESS;
    }
}
