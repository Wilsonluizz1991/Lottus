<?php

namespace App\Services\Lottus\Fechamento;

use App\Models\LotofacilConcurso;
use Illuminate\Support\Collection;

class FechamentoPatternPredictionService
{
    public function predict(
        Collection $historico,
        array $frequencyContext,
        array $delayContext,
        array $correlationContext,
        array $structureContext,
        array $cycleContext,
        LotofacilConcurso $concursoBase
    ): array {
        $draws = $this->extractDraws($historico);

        if (count($draws) < 30) {
            return $this->fallbackPattern($concursoBase);
        }

        $lastDraw = $this->extractNumbers($concursoBase);
        $window25 = array_slice($draws, -25);
        $window60 = array_slice($draws, -60);
        $window120 = array_slice($draws, -120);

        $repeatProfile = $this->repeatProfile($window60);
        $lineProfile = $this->lineProfile($window60);
        $zoneProfile = $this->zoneProfile($window60);
        $sumProfile = $this->sumProfile($window60);
        $oddProfile = $this->oddProfile($window60);
        $cyclePressure = $this->cyclePressure($cycleContext);
        $hotColdBalance = $this->hotColdBalance(
            frequencyContext: $frequencyContext,
            delayContext: $delayContext,
            cycleContext: $cycleContext
        );

        $recentSignature = $this->recentSignature(
            window25: $window25,
            window60: $window60,
            lastDraw: $lastDraw
        );

        $regime = $this->detectRegime(
            repeatProfile: $repeatProfile,
            cyclePressure: $cyclePressure,
            hotColdBalance: $hotColdBalance,
            recentSignature: $recentSignature,
            lineProfile: $lineProfile
        );

        $confidence = $this->confidence(
            repeatProfile: $repeatProfile,
            cyclePressure: $cyclePressure,
            hotColdBalance: $hotColdBalance,
            recentSignature: $recentSignature,
            lineProfile: $lineProfile,
            zoneProfile: $zoneProfile
        );

        $pattern = [
            'regime' => $regime,
            'confidence' => $confidence,
            'target_repeat_min' => $this->targetRepeatMin($regime, $repeatProfile),
            'target_repeat_max' => $this->targetRepeatMax($regime, $repeatProfile),
            'target_sum_min' => $sumProfile['p25'],
            'target_sum_max' => $sumProfile['p75'],
            'target_odd_min' => $oddProfile['p25'],
            'target_odd_max' => $oddProfile['p75'],
            'line_targets' => $lineProfile['targets'],
            'zone_targets' => $zoneProfile['targets'],
            'temperature_bias' => $this->temperatureBias($regime, $hotColdBalance),
            'strategy_bias' => $this->strategyBias($regime),
            'cycle_pressure' => $cyclePressure,
            'hot_cold_balance' => $hotColdBalance,
            'recent_signature' => $recentSignature,
            'repeat_profile' => $repeatProfile,
            'sum_profile' => $sumProfile,
            'odd_profile' => $oddProfile,
        ];

        logger()->info('FECHAMENTO_PATTERN_PREDICTION', [
            'concurso' => $concursoBase->concurso,
            'pattern' => $pattern,
        ]);

        return $pattern;
    }

    protected function fallbackPattern(LotofacilConcurso $concursoBase): array
    {
        return [
            'regime' => 'balanced_unknown',
            'confidence' => 35,
            'target_repeat_min' => 7,
            'target_repeat_max' => 11,
            'target_sum_min' => 165,
            'target_sum_max' => 220,
            'target_odd_min' => 6,
            'target_odd_max' => 9,
            'line_targets' => [1 => 3, 2 => 3, 3 => 3, 4 => 3, 5 => 3],
            'zone_targets' => [1 => 5, 2 => 5, 3 => 5],
            'temperature_bias' => ['hot' => 0.40, 'neutral' => 0.43, 'cold' => 0.17],
            'strategy_bias' => [
                'maturity_core' => 1.0,
                'cycle_return' => 1.0,
                'affinity_blocks' => 1.0,
                'persistence_recent' => 1.0,
                'balanced_window' => 1.15,
                'anti_overfit' => 1.10,
                'elite_convergence' => 1.0,
            ],
            'cycle_pressure' => 0.5,
            'hot_cold_balance' => ['hot_pressure' => 0.4, 'cold_pressure' => 0.2, 'neutral_pressure' => 0.4],
            'recent_signature' => ['repeat_trend' => 0.5, 'volatility' => 0.5],
        ];
    }

    protected function extractDraws(Collection $historico): array
    {
        $draws = [];

        foreach ($historico as $concurso) {
            $numbers = $this->extractNumbers($concurso);

            if (count($numbers) === 15) {
                $draws[] = $numbers;
            }
        }

        return $draws;
    }

    protected function extractNumbers(LotofacilConcurso|array $concurso): array
    {
        if (is_array($concurso)) {
            if (isset($concurso['dezenas']) && is_array($concurso['dezenas'])) {
                return collect($concurso['dezenas'])
                    ->map(fn ($n) => (int) $n)
                    ->filter(fn ($n) => $n > 0)
                    ->unique()
                    ->sort()
                    ->values()
                    ->toArray();
            }

            $numbers = [];

            for ($i = 1; $i <= 15; $i++) {
                $field = 'bola' . $i;

                if (isset($concurso[$field]) && is_numeric($concurso[$field])) {
                    $numbers[] = (int) $concurso[$field];
                }
            }

            if (count($numbers) === 15) {
                return collect($numbers)
                    ->filter(fn ($n) => $n > 0)
                    ->unique()
                    ->sort()
                    ->values()
                    ->toArray();
            }

            return collect($concurso)
                ->filter(fn ($value, $key) => is_numeric($value) && preg_match('/^(bola\d+|dezena\d+|d\d+)$/', (string) $key))
                ->map(fn ($n) => (int) $n)
                ->filter(fn ($n) => $n > 0 && $n <= 25)
                ->unique()
                ->sort()
                ->values()
                ->toArray();
        }

        $numbers = [];

        for ($i = 1; $i <= 15; $i++) {
            $field = 'bola' . $i;

            if (isset($concurso->{$field})) {
                $numbers[] = (int) $concurso->{$field};
            }
        }

        return collect($numbers)
            ->filter(fn ($n) => $n > 0)
            ->unique()
            ->sort()
            ->values()
            ->toArray();
    }

    protected function repeatProfile(array $draws): array
    {
        $repeats = [];

        for ($i = 1; $i < count($draws); $i++) {
            $repeats[] = count(array_intersect($draws[$i], $draws[$i - 1]));
        }

        return $this->distributionProfile($repeats, 8);
    }

    protected function lineProfile(array $draws): array
    {
        $lines = [];

        foreach ($draws as $draw) {
            foreach ($draw as $number) {
                $line = $this->line((int) $number);
                $lines[$line][] = 1;
            }
        }

        $targets = [];

        foreach (range(1, 5) as $line) {
            $targets[$line] = (int) round(count($lines[$line] ?? []) / max(1, count($draws)));
            $targets[$line] = max(2, min(4, $targets[$line]));
        }

        return [
            'targets' => $targets,
            'balance' => $this->targetBalance($targets, 5),
        ];
    }

    protected function zoneProfile(array $draws): array
    {
        $zones = [];

        foreach ($draws as $draw) {
            foreach ($draw as $number) {
                $zone = $this->zone((int) $number);
                $zones[$zone][] = 1;
            }
        }

        $targets = [];

        foreach (range(1, 3) as $zone) {
            $targets[$zone] = (int) round(count($zones[$zone] ?? []) / max(1, count($draws)));
            $targets[$zone] = max(4, min(6, $targets[$zone]));
        }

        return [
            'targets' => $targets,
            'balance' => $this->targetBalance($targets, 3),
        ];
    }

    protected function sumProfile(array $draws): array
    {
        return $this->distributionProfile(
            array_map(fn (array $draw) => array_sum($draw), $draws),
            190
        );
    }

    protected function oddProfile(array $draws): array
    {
        return $this->distributionProfile(
            array_map(fn (array $draw) => count(array_filter($draw, fn ($number) => $number % 2 !== 0)), $draws),
            7
        );
    }

    protected function distributionProfile(array $values, int $fallback): array
    {
        sort($values);

        if (empty($values)) {
            return [
                'avg' => $fallback,
                'p25' => $fallback,
                'p50' => $fallback,
                'p75' => $fallback,
                'min' => $fallback,
                'max' => $fallback,
            ];
        }

        return [
            'avg' => array_sum($values) / count($values),
            'p25' => $this->percentile($values, 0.25),
            'p50' => $this->percentile($values, 0.50),
            'p75' => $this->percentile($values, 0.75),
            'min' => min($values),
            'max' => max($values),
        ];
    }

    protected function percentile(array $values, float $percentile): int
    {
        if (empty($values)) {
            return 0;
        }

        sort($values);

        $index = (int) floor((count($values) - 1) * $percentile);

        return (int) $values[$index];
    }

    protected function cyclePressure(array $cycleContext): float
    {
        $faltantes = array_values(array_map('intval', $cycleContext['faltantes'] ?? []));
        $scores = $this->normalizeScores($cycleContext['scores'] ?? []);

        if (empty($scores)) {
            return 0.5;
        }

        $faltantesScore = 0.0;

        foreach ($faltantes as $number) {
            $faltantesScore += (float) ($scores[$number] ?? 0.0);
        }

        $faltantesScore = $faltantesScore / max(1, count($faltantes));

        return max(0.0, min(1.0, ($faltantesScore * 0.70) + (min(1.0, count($faltantes) / 10) * 0.30)));
    }

    protected function hotColdBalance(array $frequencyContext, array $delayContext, array $cycleContext): array
    {
        $frequency = $this->normalizeScores($frequencyContext['scores'] ?? []);
        $delay = $this->normalizeScores($delayContext['scores'] ?? []);
        $cycle = $this->normalizeScores($cycleContext['scores'] ?? []);

        $hot = 0;
        $cold = 0;
        $neutral = 0;

        foreach (range(1, 25) as $number) {
            $temperatureScore =
                ((float) ($frequency[$number] ?? 0.0) * 0.55) +
                ((1.0 - (float) ($delay[$number] ?? 0.0)) * 0.25) +
                ((float) ($cycle[$number] ?? 0.0) * 0.20);

            if ($temperatureScore >= 0.66) {
                $hot++;
            } elseif ($temperatureScore <= 0.38) {
                $cold++;
            } else {
                $neutral++;
            }
        }

        return [
            'hot_pressure' => $hot / 25,
            'cold_pressure' => $cold / 25,
            'neutral_pressure' => $neutral / 25,
        ];
    }

    protected function recentSignature(array $window25, array $window60, array $lastDraw): array
    {
        $recentRepeats = [];

        for ($i = 1; $i < count($window25); $i++) {
            $recentRepeats[] = count(array_intersect($window25[$i], $window25[$i - 1]));
        }

        $longRepeats = [];

        for ($i = 1; $i < count($window60); $i++) {
            $longRepeats[] = count(array_intersect($window60[$i], $window60[$i - 1]));
        }

        $recentAvg = empty($recentRepeats) ? 8 : array_sum($recentRepeats) / count($recentRepeats);
        $longAvg = empty($longRepeats) ? 8 : array_sum($longRepeats) / count($longRepeats);

        return [
            'repeat_trend' => max(0.0, min(1.0, ($recentAvg - 5) / 8)),
            'repeat_delta' => $recentAvg - $longAvg,
            'volatility' => $this->volatility($recentRepeats),
            'last_draw' => $lastDraw,
        ];
    }

    protected function volatility(array $values): float
    {
        if (count($values) < 2) {
            return 0.5;
        }

        $avg = array_sum($values) / count($values);
        $variance = 0.0;

        foreach ($values as $value) {
            $variance += pow($value - $avg, 2);
        }

        $variance = $variance / count($values);

        return max(0.0, min(1.0, sqrt($variance) / 4));
    }

    protected function detectRegime(
        array $repeatProfile,
        float $cyclePressure,
        array $hotColdBalance,
        array $recentSignature,
        array $lineProfile
    ): string {
        if (($recentSignature['repeat_delta'] ?? 0) >= 0.75 && ($recentSignature['repeat_trend'] ?? 0) >= 0.55) {
            return 'high_repetition';
        }

        if ($cyclePressure >= 0.68 && ($hotColdBalance['cold_pressure'] ?? 0) >= 0.20) {
            return 'cycle_return';
        }

        if (($hotColdBalance['hot_pressure'] ?? 0) >= 0.44 && $cyclePressure < 0.58) {
            return 'hot_persistence';
        }

        if (($recentSignature['volatility'] ?? 0) >= 0.62) {
            return 'volatile_transition';
        }

        if (($lineProfile['balance'] ?? 0) >= 0.78) {
            return 'balanced_distribution';
        }

        return 'elite_convergence';
    }

    protected function confidence(
        array $repeatProfile,
        float $cyclePressure,
        array $hotColdBalance,
        array $recentSignature,
        array $lineProfile,
        array $zoneProfile
    ): int {
        $score = 35.0;

        $score += min(18.0, abs(($recentSignature['repeat_delta'] ?? 0)) * 9.0);
        $score += min(14.0, $cyclePressure * 14.0);
        $score += min(12.0, ($lineProfile['balance'] ?? 0.0) * 12.0);
        $score += min(10.0, ($zoneProfile['balance'] ?? 0.0) * 10.0);
        $score += min(8.0, (($hotColdBalance['neutral_pressure'] ?? 0.0) + 0.30) * 8.0);
        $score -= min(12.0, ($recentSignature['volatility'] ?? 0.0) * 12.0);

        return (int) max(0, min(100, round($score)));
    }

    protected function targetRepeatMin(string $regime, array $repeatProfile): int
    {
        return match ($regime) {
            'high_repetition', 'hot_persistence' => max(8, (int) $repeatProfile['p50']),
            'cycle_return', 'volatile_transition' => max(6, (int) $repeatProfile['p25']),
            default => max(7, (int) $repeatProfile['p25']),
        };
    }

    protected function targetRepeatMax(string $regime, array $repeatProfile): int
    {
        return match ($regime) {
            'high_repetition', 'hot_persistence' => min(13, (int) $repeatProfile['p75'] + 1),
            'cycle_return', 'volatile_transition' => min(12, (int) $repeatProfile['p75']),
            default => min(12, (int) $repeatProfile['p75'] + 1),
        };
    }

    protected function temperatureBias(string $regime, array $hotColdBalance): array
    {
        return match ($regime) {
            'high_repetition', 'hot_persistence' => ['hot' => 0.48, 'neutral' => 0.40, 'cold' => 0.12],
            'cycle_return' => ['hot' => 0.34, 'neutral' => 0.42, 'cold' => 0.24],
            'volatile_transition' => ['hot' => 0.36, 'neutral' => 0.46, 'cold' => 0.18],
            'balanced_distribution' => ['hot' => 0.40, 'neutral' => 0.43, 'cold' => 0.17],
            default => ['hot' => 0.42, 'neutral' => 0.42, 'cold' => 0.16],
        };
    }

    protected function strategyBias(string $regime): array
    {
        return match ($regime) {
            'high_repetition' => [
                'maturity_core' => 1.15,
                'cycle_return' => 0.85,
                'affinity_blocks' => 1.10,
                'persistence_recent' => 1.30,
                'balanced_window' => 1.0,
                'anti_overfit' => 0.90,
                'elite_convergence' => 1.05,
            ],
            'cycle_return' => [
                'maturity_core' => 0.95,
                'cycle_return' => 1.35,
                'affinity_blocks' => 1.00,
                'persistence_recent' => 0.85,
                'balanced_window' => 1.05,
                'anti_overfit' => 1.10,
                'elite_convergence' => 1.0,
            ],
            'hot_persistence' => [
                'maturity_core' => 1.18,
                'cycle_return' => 0.82,
                'affinity_blocks' => 1.10,
                'persistence_recent' => 1.25,
                'balanced_window' => 1.0,
                'anti_overfit' => 0.90,
                'elite_convergence' => 1.12,
            ],
            'volatile_transition' => [
                'maturity_core' => 0.90,
                'cycle_return' => 1.10,
                'affinity_blocks' => 1.05,
                'persistence_recent' => 0.80,
                'balanced_window' => 1.15,
                'anti_overfit' => 1.25,
                'elite_convergence' => 0.95,
            ],
            'balanced_distribution' => [
                'maturity_core' => 1.0,
                'cycle_return' => 1.0,
                'affinity_blocks' => 1.05,
                'persistence_recent' => 1.0,
                'balanced_window' => 1.25,
                'anti_overfit' => 1.12,
                'elite_convergence' => 1.0,
            ],
            default => [
                'maturity_core' => 1.05,
                'cycle_return' => 1.0,
                'affinity_blocks' => 1.12,
                'persistence_recent' => 1.0,
                'balanced_window' => 1.05,
                'anti_overfit' => 1.0,
                'elite_convergence' => 1.20,
            ],
        };
    }

    protected function normalizeScores(array $scores): array
    {
        $normalized = [];
        $values = [];

        foreach (range(1, 25) as $number) {
            $values[$number] = (float) ($scores[$number] ?? 0.0);
        }

        $min = min($values);
        $max = max($values);

        foreach ($values as $number => $value) {
            if ($max <= $min) {
                $normalized[$number] = 0.5;
                continue;
            }

            $normalized[$number] = ($value - $min) / ($max - $min);
        }

        return $normalized;
    }

    protected function targetBalance(array $targets, int $buckets): float
    {
        if ($buckets <= 0 || empty($targets)) {
            return 0.0;
        }

        $total = array_sum($targets);
        $expected = $total / $buckets;
        $distance = 0.0;

        foreach ($targets as $value) {
            $distance += abs($value - $expected);
        }

        return max(0.0, 1.0 - ($distance / max(1.0, $total)));
    }

    protected function line(int $number): int
    {
        return (int) floor(($number - 1) / 5) + 1;
    }

    protected function zone(int $number): int
    {
        if ($number <= 8) {
            return 1;
        }

        if ($number <= 17) {
            return 2;
        }

        return 3;
    }
}
