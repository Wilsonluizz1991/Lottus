<?php

namespace App\Console\Commands\Lottus;

use App\Models\LotofacilConcurso;
use App\Services\Lottus\Learning\LearningEngine;
use Illuminate\Console\Command;

class AprenderFechamentoCommand extends Command
{
    protected $signature = 'lottus:fechamento-aprender {concurso}';

    protected $description = 'Processa aprendizado do motor de fechamento para um concurso específico da Lotofácil';

    public function handle(
        LearningEngine $learningEngine
    ): int {
        $concurso = (int) $this->argument('concurso');

        $concursoAtual = LotofacilConcurso::query()
            ->where('concurso', $concurso)
            ->first();

        if (! $concursoAtual) {
            $this->error("Concurso {$concurso} não encontrado.");

            return self::FAILURE;
        }

        $this->info("Iniciando aprendizado do fechamento para o concurso {$concurso}...");

        $learningEngine->learnFromContest($concursoAtual);

        $this->info("Aprendizado do fechamento processado com sucesso para o concurso {$concurso}.");

        return self::SUCCESS;
    }
}