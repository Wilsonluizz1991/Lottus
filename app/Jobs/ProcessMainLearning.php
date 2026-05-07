<?php

namespace App\Jobs;

use App\Services\Lottus\MainLearning\LottusMainAdaptiveLearningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessMainLearning implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 1200;

    public function __construct(
        public int $learningRunId
    ) {
    }

    public function handle(LottusMainAdaptiveLearningService $learningService): void
    {
        $learningService->processRun($this->learningRunId);
    }
}
