<?php

namespace App\Services\Lottus\Learning;

use App\Models\MotorLearningWeight;

class MotorLearningWeightService
{
    public function getWeights(string $strategy, array $fallback): array
    {
        $record = MotorLearningWeight::query()
            ->where('engine', 'fechamento')
            ->where('strategy', $strategy)
            ->first();

        if (! $record || empty($record->weights)) {
            return $fallback;
        }

        return $this->normalizeWeights(array_merge($fallback, $record->weights));
    }

    public function updateWeights(
        string $strategy,
        array $currentWeights,
        array $performanceByFeature,
        int $concurso,
        float $error,
        float $score
    ): MotorLearningWeight {
        $currentWeights = $this->normalizeWeights($currentWeights);
        $performanceByFeature = $this->normalizeWeights(array_merge(
            array_fill_keys(array_keys($currentWeights), 0.0),
            $performanceByFeature
        ));

        $record = MotorLearningWeight::query()->firstOrCreate(
            [
                'engine' => 'fechamento',
                'strategy' => $strategy,
            ],
            [
                'weights' => $currentWeights,
                'learning_rate' => 0.015,
                'samples' => 0,
            ]
        );

        $learningRate = max(0.001, min(0.08, (float) $record->learning_rate));
        $newWeights = [];

        foreach ($currentWeights as $key => $weight) {
            $featurePerformance = (float) ($performanceByFeature[$key] ?? 0.0);
            $delta = $learningRate * ($featurePerformance - $weight);

            $newWeights[$key] = max(0.01, $weight + $delta);
        }

        $newWeights = $this->normalizeWeights($newWeights);

        $record->update([
            'weights' => $newWeights,
            'samples' => ((int) $record->samples) + 1,
            'last_concurso' => $concurso,
            'last_error' => $error,
            'last_score' => $score,
            'updated_by_learning_at' => now(),
        ]);

        return $record->refresh();
    }

    protected function normalizeWeights(array $weights): array
    {
        $clean = [];

        foreach ($weights as $key => $value) {
            $clean[$key] = max(0.0, (float) $value);
        }

        $sum = array_sum($clean);

        if ($sum <= 0) {
            $count = max(1, count($clean));

            return array_map(
                fn () => round(1 / $count, 8),
                $clean
            );
        }

        foreach ($clean as $key => $value) {
            $clean[$key] = round($value / $sum, 8);
        }

        return $clean;
    }
}