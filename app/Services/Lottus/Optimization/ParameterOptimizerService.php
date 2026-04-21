<?php

namespace App\Services\Lottus\Optimization;

use App\Services\Lottus\Backtest\BacktestService;

class ParameterOptimizerService
{
    public function __construct(
        protected BacktestService $backtestService
    ) {
    }

    public function optimize(int $inicio, int $fim, int $jogos = 5, int $maxTests = 12, ?callable $progressCallback = null): array
    {
        $best = null;
        $tests = [];

        $combinations = $this->generateCombinations($maxTests);
        $total = count($combinations);

        foreach ($combinations as $index => $params) {
            $startedAt = microtime(true);

            $this->applyParams($params);

            $result = $this->backtestService->run($inicio, $fim, $jogos);
            $score = $this->calculateScore($result);

            $test = [
                'test_number' => $index + 1,
                'params' => $params,
                'score' => $score,
                'result' => $result,
                'duration_seconds' => round(microtime(true) - $startedAt, 2),
            ];

            $tests[] = $test;

            if ($best === null || $score > $best['score']) {
                $best = $test;
            }

            if ($progressCallback) {
                $progressCallback($test, $best, $total);
            }
        }

        return [
            'total_tests' => $total,
            'best' => $best,
            'tests' => $tests,
        ];
    }

    protected function generateCombinations(int $maxTests): array
    {
        $sets = [];
        $weights = [0.15, 0.20, 0.25, 0.30, 0.35, 0.40];

        foreach ($weights as $freq) {
            foreach ($weights as $delay) {
                foreach ($weights as $corr) {
                    foreach ($weights as $cycle) {
                        $sum = $freq + $delay + $corr + $cycle;

                        if ($sum < 0.95 || $sum > 1.10) {
                            continue;
                        }

                        $sets[] = [
                            'frequency' => $freq,
                            'delay' => $delay,
                            'correlation' => $corr,
                            'cycle' => $cycle,
                        ];
                    }
                }
            }
        }

        usort($sets, function ($a, $b) {
            return $this->distanceFromBalanced($a) <=> $this->distanceFromBalanced($b);
        });

        $prioritized = [];
        $seen = [];

        foreach ($sets as $set) {
            $key = implode('-', $set);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $prioritized[] = $set;

            if (count($prioritized) >= $maxTests) {
                break;
            }
        }

        return $prioritized;
    }

    protected function distanceFromBalanced(array $params): float
    {
        $target = [
            'frequency' => 0.30,
            'delay' => 0.20,
            'correlation' => 0.25,
            'cycle' => 0.25,
        ];

        return
            abs($params['frequency'] - $target['frequency']) +
            abs($params['delay'] - $target['delay']) +
            abs($params['correlation'] - $target['correlation']) +
            abs($params['cycle'] - $target['cycle']);
    }

    protected function applyParams(array $params): void
    {
        config([
            'lottus.weights.frequency' => $params['frequency'],
            'lottus.weights.delay' => $params['delay'],
            'lottus.weights.correlation' => $params['correlation'],
            'lottus.weights.cycle' => $params['cycle'],
        ]);
    }

    protected function calculateScore(array $result): float
    {
        return
            ($result['faixas'][13] * 20) +
            ($result['faixas'][12] * 4) +
            ($result['faixas'][11] * 1);
    }
}