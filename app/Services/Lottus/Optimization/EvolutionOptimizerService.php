<?php

namespace App\Services\Lottus\Optimization;

use App\Services\Lottus\Backtest\BacktestService;

class EvolutionOptimizerService
{
    public function __construct(
        protected BacktestService $backtestService
    ) {
    }

    public function optimize(
        int $inicio,
        int $fim,
        int $jogos = 5,
        int $populationSize = 8,
        int $generations = 4,
        ?callable $progressCallback = null
    ): array {
        $population = $this->createInitialPopulation($populationSize);
        $history = [];
        $globalBest = null;

        for ($generation = 1; $generation <= $generations; $generation++) {
            $evaluated = [];

            foreach ($population as $index => $params) {
                $startedAt = microtime(true);

                $this->applyParams($params);

                $result = $this->backtestService->run($inicio, $fim, $jogos);
                $fitness = $this->calculateFitness($result);

                $individual = [
                    'generation' => $generation,
                    'individual' => $index + 1,
                    'params' => $params,
                    'fitness' => $fitness,
                    'result' => $result,
                    'duration_seconds' => round(microtime(true) - $startedAt, 2),
                ];

                $evaluated[] = $individual;

                if ($globalBest === null || $individual['fitness'] > $globalBest['fitness']) {
                    $globalBest = $individual;
                }

                if ($progressCallback) {
                    $progressCallback($individual, $globalBest, $generation, $generations);
                }
            }

            usort($evaluated, fn ($a, $b) => $b['fitness'] <=> $a['fitness']);

            $history[] = [
                'generation' => $generation,
                'best' => $evaluated[0],
                'population' => $evaluated,
            ];

            $parents = array_slice($evaluated, 0, max(2, (int) floor($populationSize * 0.35)));
            $population = $this->breedNextGeneration($parents, $populationSize);
        }

        return [
            'best' => $globalBest,
            'history' => $history,
        ];
    }

    protected function createInitialPopulation(int $populationSize): array
    {
        $seedPopulation = [
            ['frequency' => 0.25, 'delay' => 0.25, 'correlation' => 0.25, 'cycle' => 0.25],
            ['frequency' => 0.30, 'delay' => 0.20, 'correlation' => 0.20, 'cycle' => 0.25],
            ['frequency' => 0.25, 'delay' => 0.20, 'correlation' => 0.25, 'cycle' => 0.30],
            ['frequency' => 0.20, 'delay' => 0.30, 'correlation' => 0.20, 'cycle' => 0.30],
            ['frequency' => 0.30, 'delay' => 0.15, 'correlation' => 0.25, 'cycle' => 0.30],
            ['frequency' => 0.20, 'delay' => 0.25, 'correlation' => 0.30, 'cycle' => 0.25],
        ];

        $population = [];

        foreach ($seedPopulation as $seed) {
            $population[] = $this->normalize($seed);
        }

        while (count($population) < $populationSize) {
            $population[] = $this->normalize([
                'frequency' => $this->randomWeight(),
                'delay' => $this->randomWeight(),
                'correlation' => $this->randomWeight(),
                'cycle' => $this->randomWeight(),
            ]);
        }

        return array_slice($population, 0, $populationSize);
    }

    protected function breedNextGeneration(array $parents, int $populationSize): array
    {
        $next = [];

        foreach ($parents as $parent) {
            $next[] = $parent['params'];
        }

        while (count($next) < $populationSize) {
            $parentA = $parents[array_rand($parents)]['params'];
            $parentB = $parents[array_rand($parents)]['params'];

            $child = $this->crossover($parentA, $parentB);
            $child = $this->mutate($child);

            $next[] = $this->normalize($child);
        }

        return array_slice($next, 0, $populationSize);
    }

    protected function crossover(array $a, array $b): array
    {
        return [
            'frequency' => ($a['frequency'] + $b['frequency']) / 2,
            'delay' => ($a['delay'] + $b['delay']) / 2,
            'correlation' => ($a['correlation'] + $b['correlation']) / 2,
            'cycle' => ($a['cycle'] + $b['cycle']) / 2,
        ];
    }

    protected function mutate(array $child): array
    {
        foreach ($child as $key => $value) {
            if (mt_rand(1, 100) <= 65) {
                $delta = mt_rand(-4, 4) / 100;
                $child[$key] = max(0.05, min(0.60, $value + $delta));
            }
        }

        return $child;
    }

    protected function normalize(array $params): array
    {
        $sum = array_sum($params);

        if ($sum <= 0) {
            return [
                'frequency' => 0.25,
                'delay' => 0.25,
                'correlation' => 0.25,
                'cycle' => 0.25,
            ];
        }

        foreach ($params as $key => $value) {
            $params[$key] = round($value / $sum, 4);
        }

        return $params;
    }

    protected function randomWeight(): float
    {
        return mt_rand(10, 50) / 100;
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

    protected function calculateFitness(array $result): float
    {
        return
        ($result['faixas'][15] * 5000) +
        ($result['faixas'][14] * 800) +
        ($result['faixas'][13] * 30) +
        ($result['faixas'][12] * 2) +
        ($result['faixas'][11] * 0.5);
    }
}