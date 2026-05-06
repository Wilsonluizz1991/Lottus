<?php

namespace App\Console\Commands\Lottus;

use App\Models\LotofacilConcurso;
use App\Services\Lottus\Learning\AdaptiveLearningRunService;
use Illuminate\Console\Command;

class AprenderFechamentoCommand extends Command
{
    protected $signature = 'lottus:fechamento-aprender {concurso}';

    protected $description = 'Processa aprendizado do motor de fechamento para um concurso específico da Lotofácil';

    public function handle(AdaptiveLearningRunService $runService): int
    {
        $concurso = (int) $this->argument('concurso');

        $concursoAtual = LotofacilConcurso::query()
            ->where('concurso', $concurso)
            ->first();

        if (! $concursoAtual) {
            $this->error("Concurso {$concurso} não encontrado.");

            return self::FAILURE;
        }

        $this->info("Iniciando aprendizado controlado do fechamento para o concurso {$concurso}...");

        $run = $runService->enqueue(
            concurso: $concursoAtual->concurso,
            triggeredBy: 'manual_legacy_command',
            force: true
        );

        $processedRun = $runService->processRun($run->id);

        $this->info("Aprendizado do fechamento processado com sucesso para o concurso {$concurso}.");
        $this->line("Run: {$processedRun->id}");
        $this->line("Status: {$processedRun->status}");
        $this->line("Versão de calibração: {$processedRun->calibration_version}");

        return self::SUCCESS;
    }
}
