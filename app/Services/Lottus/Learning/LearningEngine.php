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
    ): array {
        $summary = [
            'processed' => 0,
            'failed' => 0,
            'strategies' => [],
        ];

        foreach ($this->registry->strategies() as $strategy) {
            try {
                $result = $strategy->learn($concurso);

                $summary['processed']++;
                $summary['strategies'][] = [
                    'engine' => $strategy->engine(),
                    'strategy' => $strategy->strategy(),
                    'status' => 'completed',
                    'result' => $result,
                ];
            } catch (\Throwable $e) {
                $summary['failed']++;
                $summary['strategies'][] = [
                    'engine' => $strategy->engine(),
                    'strategy' => $strategy->strategy(),
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];

                logger()->error('LOTTUS_LEARNING_STRATEGY_FAILED', [
                    'engine' => $strategy->engine(),
                    'strategy' => $strategy->strategy(),
                    'concurso' => $concurso->concurso,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }
}
