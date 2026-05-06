<?php

namespace App\Jobs\Lottus;

use App\Jobs\ProcessAdaptiveLearning;
use App\Services\Lottus\Learning\AdaptiveLearningRunService;
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

    public function handle(AdaptiveLearningRunService $runService): void
    {
        $run = $runService->enqueue(
            concurso: $this->concurso,
            triggeredBy: 'legacy_job',
            force: false
        );

        if (! $run) {
            return;
        }

        ProcessAdaptiveLearning::dispatch($run->id)->onQueue('learning');
    }

    public function failed(\Throwable $exception): void
    {
        logger()->error('FECHAMENTO_LEARNING_JOB_FAILED', [
            'concurso' => $this->concurso,
            'error' => $exception->getMessage(),
        ]);
    }
}
