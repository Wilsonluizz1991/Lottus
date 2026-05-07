<?php

namespace App\Listeners;

use App\Events\LotofacilConcursoSincronizado;
use App\Jobs\ProcessMainLearning;
use App\Models\LottusMainLearningRun;
use App\Services\Lottus\MainLearning\LottusMainAdaptiveLearningService;
use Illuminate\Support\Facades\Log;

class TriggerMainLearning
{
    public function __construct(
        protected LottusMainAdaptiveLearningService $learningService
    ) {
    }

    public function handle(LotofacilConcursoSincronizado $event): void
    {
        try {
            $run = $this->learningService->enqueue($event->concurso, false);

            if (! $run || $run->status !== LottusMainLearningRun::STATUS_PENDING) {
                return;
            }

            ProcessMainLearning::dispatch($run->id)
                ->onQueue((string) config('lottus_main_learning.queue', 'learning'));

            Log::info('LOTTUS_MAIN_LEARNING_DISPATCHED', [
                'run_id' => $run->id,
                'concurso' => $run->concurso,
                'queue' => config('lottus_main_learning.queue', 'learning'),
            ]);
        } catch (\Throwable $e) {
            Log::error('LOTTUS_MAIN_LEARNING_DISPATCH_FAILED', [
                'concurso' => $event->concurso,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }
    }
}
