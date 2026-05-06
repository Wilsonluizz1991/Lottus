<?php

namespace App\Listeners;

use App\Events\LotofacilConcursoSincronizado;
use App\Jobs\ProcessAdaptiveLearning;
use App\Models\LottusLearningRun;
use App\Services\Lottus\Learning\AdaptiveLearningRunService;
use Illuminate\Support\Facades\Log;

class TriggerAdaptiveLearning
{
    public function __construct(
        protected AdaptiveLearningRunService $runService
    ) {
    }

    public function handle(LotofacilConcursoSincronizado $event): void
    {
        try {
            $run = $this->runService->enqueue(
                concurso: $event->concurso,
                triggeredBy: 'sync_event',
                force: false
            );

            if (! $run) {
                return;
            }

            if ($run->status !== LottusLearningRun::STATUS_PENDING) {
                return;
            }

            ProcessAdaptiveLearning::dispatch($run->id)->onQueue('learning');

            Log::info('LOTTUS_ADAPTIVE_LEARNING_DISPATCHED', [
                'run_id' => $run->id,
                'concurso' => $run->concurso,
                'calibration_version' => $run->calibration_version,
                'queue' => 'learning',
            ]);
        } catch (\Throwable $e) {
            Log::error('LOTTUS_ADAPTIVE_LEARNING_DISPATCH_FAILED', [
                'concurso' => $event->concurso,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }
    }
}
