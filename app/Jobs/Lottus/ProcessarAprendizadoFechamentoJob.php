<?php

namespace App\Jobs\Lottus;

use App\Models\LotofacilConcurso;
use App\Services\Lottus\Learning\LearningEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessarAprendizadoFechamentoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(
        public int $concurso
    ) {
    }

    public function handle(
        LearningEngine $learningEngine
    ): void {
        $concursoAtual = LotofacilConcurso::query()
            ->where('concurso', $this->concurso)
            ->first();

        if (! $concursoAtual) {
            logger()->warning('FECHAMENTO_LEARNING_JOB_CONCURSO_NOT_FOUND', [
                'concurso' => $this->concurso,
            ]);

            return;
        }

        $learningEngine->learnFromContest($concursoAtual);

        logger()->info('FECHAMENTO_LEARNING_JOB_DONE', [
            'concurso' => $this->concurso,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        logger()->error('FECHAMENTO_LEARNING_JOB_FAILED', [
            'concurso' => $this->concurso,
            'error' => $exception->getMessage(),
        ]);
    }
}