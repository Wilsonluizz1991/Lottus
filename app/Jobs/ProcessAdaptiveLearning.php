<?php

namespace App\Jobs;

use App\Services\Lottus\Learning\AdaptiveLearningRunService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAdaptiveLearning implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(
        public int $learningRunId
    ) {
    }

    public function handle(AdaptiveLearningRunService $runService): void
    {
        $runService->processRun($this->learningRunId);
    }
}
