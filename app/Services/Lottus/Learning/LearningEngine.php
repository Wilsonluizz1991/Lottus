<?php

namespace App\Services\Lottus\Learning;

use App\Models\LotofacilConcurso;

class LearningEngine
{
    public function __construct(
        protected LearningRegistry $registry
    ) {
    }

    public function learnFromContest(
        LotofacilConcurso $concurso
    ): void {
        foreach ($this->registry->strategies() as $strategy) {
            try {
                $strategy->learn($concurso);
            } catch (\Throwable $e) {
                logger()->error('LOTTUS_LEARNING_STRATEGY_FAILED', [
                    'engine' => $strategy->engine(),
                    'strategy' => $strategy->strategy(),
                    'concurso' => $concurso->concurso,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}