<?php

namespace App\Services\Lottus\Fechamento;

use App\Models\LotofacilConcurso;

class FechamentoCandidateSelector
{
    public function select(
        int $quantidadeDezenas,
        array $frequencyContext,
        array $delayContext,
        array $correlationContext,
        array $structureContext,
        array $cycleContext,
        LotofacilConcurso $concursoBase
    ): array {
        $lastDraw = $this->extractNumbers($concursoBase);
        $frequencyScores = $this->normalizeScores($frequencyContext['scores'] ?? []);
        $delayScores = $this->normalizeScores($delayContext['scores'] ?? []);
        $cycleScores = $this->normalizeScores($cycleContext['scores'] ?? []);
        $pairScores = $correlationContext['pair_scores'] ?? [];
        $faltantes = array_values(array_map('intval', $cycleContext['faltantes'] ?? []));

        $numberScores = [];

        foreach (range(1, 25) as $number) {
            $frequency = (float) ($frequencyScores[$number] ?? 0.0);
            $delay = (float) ($delayScores[$number] ?? 0.0);
            $cycle = (float) ($cycleScores[$number] ?? 0.0);
            $correlation = $this->averageCorrelation($number, $pairScores);
            $lastDrawPresence = in_array($number, $lastDraw, true) ? 1.0 : 0.0;
            $cycleMissingPresence = in_array($number, $faltantes, true) ? 1.0 : 0.0;

            $maturity = $this->maturityScore(
                frequency: $frequency,
                delay: $delay,
                cycle: $cycle,
                correlation: $correlation,
                lastDrawPresence: $lastDrawPresence,
                cycleMissingPresence: $cycleMissingPresence
            );

            $stability = $this->stabilityScore(
                frequency: $frequency,
                delay: $delay,
                cycle: $cycle
            );

            $affinity = $this->affinityScore(
                number: $number,
                pairScores: $pairScores,
                lastDraw: $lastDraw
            );

            $dynamicVariance = $this->dynamicContextVariance(
                number: $number,
                concursoBase: $concursoBase,
                quantidadeDezenas: $quantidadeDezenas
            );

            $score =
                ($maturity * 0.48) +
                ($stability * 0.22) +
                ($affinity * 0.16) +
                ($correlation * 0.08) +
                ($lastDrawPresence * 0.04) +
                ($cycleMissingPresence * 0.02) +
                $dynamicVariance;

            $score -= $this->extremeBiasPenalty($frequency, $delay, $cycle);

            $numberScores[$number] = [
                'number' => $number,
                'score' => round($score, 8),
                'maturity' => round($maturity, 8),
                'stability' => round($stability, 8),
                'affinity' => round($affinity, 8),
                'frequency' => $frequency,
                'delay' => $delay,
                'cycle' => $cycle,
                'correlation' => $correlation,
                'last_draw_presence' => $lastDrawPresence,
                'cycle_missing_presence' => $cycleMissingPresence,
                'dynamic_variance' => $dynamicVariance,
                'temperature' => $this->temperature($frequency, $delay, $cycle),
                'line' => $this->line($number),
                'zone' => $this->zone($number),
                'is_edge' => $this->isEdge($number),
            ];
        }

        $selected = $this->selectDynamicBase(
            quantidadeDezenas: $quantidadeDezenas,
            numberScores: $numberScores,
            lastDraw: $lastDraw,
            faltantes: $faltantes,
            concursoBase: $concursoBase
        );

        if (count($selected) !== $quantidadeDezenas) {
            $selected = $this->fallbackBalancedSelection($numberScores, $quantidadeDezenas);
        }

        if (count($selected) !== $quantidadeDezenas) {
            $selected = $this->fallbackTopSelection($numberScores, $quantidadeDezenas);
        }

        logger()->info('FECHAMENTO_DYNAMIC_SELECTOR', [
            'concurso' => $concursoBase->concurso,
            'quantidade_dezenas' => $quantidadeDezenas,
            'selected' => $selected,
            'profile' => $this->selectionProfile($selected, $numberScores),
            'scores' => $numberScores,
        ]);

        sort($selected);

        return $selected;
    }

    protected function selectDynamicBase(
        int $quantidadeDezenas,
        array $numberScores,
        array $lastDraw,
        array $faltantes,
        LotofacilConcurso $concursoBase
    ): array {
        $selected = [];
        $ranked = array_values($numberScores);

        usort($ranked, function (array $a, array $b): int {
            if (($a['score'] ?? 0) === ($b['score'] ?? 0)) {
                return ((int) $a['number']) <=> ((int) $b['number']);
            }

            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });

        $quotas = $this->temperatureQuotas($quantidadeDezenas);

        foreach (['hot', 'neutral', 'cold'] as $temperature) {
            $needed = $quotas[$temperature] ?? 0;

            if ($needed <= 0) {
                continue;
            }

            $bucket = array_values(array_filter(
                $ranked,
                fn (array $item) => ($item['temperature'] ?? 'neutral') === $temperature
            ));

            $this->fillFromBucket(
                selected: $selected,
                bucket: $bucket,
                needed: $needed,
                quantidadeDezenas: $quantidadeDezenas,
                numberScores: $numberScores
            );
        }

        if (count($selected) < $quantidadeDezenas) {
            $cycleBucket = array_values(array_filter(
                $ranked,
                fn (array $item) => in_array((int) $item['number'], $faltantes, true)
            ));

            $this->fillFromBucket(
                selected: $selected,
                bucket: $cycleBucket,
                needed: min(2, $quantidadeDezenas - count($selected)),
                quantidadeDezenas: $quantidadeDezenas,
                numberScores: $numberScores
            );
        }

        if (count($selected) < $quantidadeDezenas) {
            $recentBucket = array_values(array_filter(
                $ranked,
                fn (array $item) => in_array((int) $item['number'], $lastDraw, true)
            ));

            $this->fillFromBucket(
                selected: $selected,
                bucket: $recentBucket,
                needed: min(3, $quantidadeDezenas - count($selected)),
                quantidadeDezenas: $quantidadeDezenas,
                numberScores: $numberScores
            );
        }

        if (count($selected) < $quantidadeDezenas) {
            $rotated = $this->rotateRankedPool($ranked, $concursoBase, $quantidadeDezenas);

            $this->fillFromBucket(
                selected: $selected,
                bucket: $rotated,
                needed: $quantidadeDezenas - count($selected),
                quantidadeDezenas: $quantidadeDezenas,
                numberScores: $numberScores
            );
        }

        $selected = $this->repairSelection(
            selected: $selected,
            numberScores: $numberScores,
            quantidadeDezenas: $quantidadeDezenas,
            ranked: $ranked
        );

        $selected = array_values(array_unique(array_map('intval', $selected)));
        sort($selected);

        return $selected;
    }

    protected function fillFromBucket(
        array &$selected,
        array $bucket,
        int $needed,
        int $quantidadeDezenas,
        array $numberScores
    ): void {
        foreach ($bucket as $candidate) {
            if ($needed <= 0 || count($selected) >= $quantidadeDezenas) {
                break;
            }

            $number = (int) ($candidate['number'] ?? 0);

            if ($number < 1 || $number > 25 || in_array($number, $selected, true)) {
                continue;
            }

            $test = $selected;
            $test[] = $number;
            $test = array_values(array_unique(array_map('intval', $test)));
            sort($test);

            if (! $this->canAcceptNumber($test, $numberScores, $quantidadeDezenas)) {
                continue;
            }

            $selected = $test;
            $needed--;
        }
    }

    protected function repairSelection(
        array $selected,
        array $numberScores,
        int $quantidadeDezenas,
        array $ranked
    ): array {
        $selected = array_values(array_unique(array_map('intval', $selected)));
        sort($selected);

        while (count($selected) > $quantidadeDezenas) {
            $remove = $this->weakestSelectedNumber($selected, $numberScores);

            if ($remove === null) {
                break;
            }

            $selected = array_values(array_diff($selected, [$remove]));
        }

        while (count($selected) < $quantidadeDezenas) {
            $added = false;

            foreach ($ranked as $candidate) {
                $number = (int) ($candidate['number'] ?? 0);

                if (in_array($number, $selected, true)) {
                    continue;
                }

                $test = $selected;
                $test[] = $number;
                $test = array_values(array_unique(array_map('intval', $test)));
                sort($test);

                if (! $this->canAcceptNumber($test, $numberScores, $quantidadeDezenas, true)) {
                    continue;
                }

                $selected = $test;
                $added = true;
                break;
            }

            if (! $added) {
                break;
            }
        }

        return $selected;
    }

    protected function canAcceptNumber(
        array $selected,
        array $numberScores,
        int $quantidadeDezenas,
        bool $relaxed = false
    ): bool {
        $lineLimits = $this->lineLimits($quantidadeDezenas, $relaxed);
        $zoneLimits = $this->zoneLimits($quantidadeDezenas, $relaxed);
        $temperatureLimits = $this->temperatureLimits($quantidadeDezenas, $relaxed);

        $lines = [];
        $zones = [];
        $temperatures = [];

        foreach ($selected as $number) {
            $line = $this->line((int) $number);
            $zone = $this->zone((int) $number);
            $temperature = $numberScores[(int) $number]['temperature'] ?? 'neutral';

            $lines[$line] = ($lines[$line] ?? 0) + 1;
            $zones[$zone] = ($zones[$zone] ?? 0) + 1;
            $temperatures[$temperature] = ($temperatures[$temperature] ?? 0) + 1;
        }

        foreach ($lines as $line => $count) {
            if ($count > ($lineLimits['max'][$line] ?? 5)) {
                return false;
            }
        }

        foreach ($zones as $zone => $count) {
            if ($count > ($zoneLimits['max'][$zone] ?? 10)) {
                return false;
            }
        }

        foreach ($temperatures as $temperature => $count) {
            if ($count > ($temperatureLimits['max'][$temperature] ?? $quantidadeDezenas)) {
                return false;
            }
        }

        return true;
    }

    protected function fallbackBalancedSelection(array $numberScores, int $quantidadeDezenas): array
    {
        $ranked = array_values($numberScores);

        usort($ranked, fn (array $a, array $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        $selected = [];

        foreach ($ranked as $candidate) {
            if (count($selected) >= $quantidadeDezenas) {
                break;
            }

            $number = (int) ($candidate['number'] ?? 0);

            if (in_array($number, $selected, true)) {
                continue;
            }

            $selected[] = $number;
        }

        sort($selected);

        return $selected;
    }

    protected function fallbackTopSelection(array $numberScores, int $quantidadeDezenas): array
    {
        $ranked = array_values($numberScores);

        usort($ranked, fn (array $a, array $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        $selected = array_map(
            fn (array $item) => (int) $item['number'],
            array_slice($ranked, 0, $quantidadeDezenas)
        );

        sort($selected);

        return $selected;
    }

    protected function maturityScore(
        float $frequency,
        float $delay,
        float $cycle,
        float $correlation,
        float $lastDrawPresence,
        float $cycleMissingPresence
    ): float {
        return max(0.0, min(1.0,
            ($frequency * 0.30) +
            ($delay * 0.18) +
            ($cycle * 0.22) +
            ($correlation * 0.14) +
            ($lastDrawPresence * 0.06) +
            ($cycleMissingPresence * 0.10)
        ));
    }

    protected function stabilityScore(float $frequency, float $delay, float $cycle): float
    {
        $frequencyStability = 1.0 - min(1.0, abs($frequency - 0.55) / 0.55);
        $delayStability = 1.0 - min(1.0, abs($delay - 0.45) / 0.55);
        $cycleStability = 1.0 - min(1.0, abs($cycle - 0.50) / 0.50);

        return max(0.0, min(1.0,
            ($frequencyStability * 0.42) +
            ($delayStability * 0.28) +
            ($cycleStability * 0.30)
        ));
    }

    protected function affinityScore(int $number, array $pairScores, array $lastDraw): float
    {
        $total = 0.0;
        $count = 0;

        foreach ($lastDraw as $other) {
            $other = (int) $other;

            if ($other === $number) {
                continue;
            }

            $total += (float) ($pairScores[$number][$other] ?? $pairScores[$other][$number] ?? 0.0);
            $count++;
        }

        if ($count === 0) {
            return 0.5;
        }

        return $this->sigmoid($total / $count);
    }

    protected function averageCorrelation(int $number, array $pairScores): float
    {
        $total = 0.0;
        $count = 0;

        foreach (range(1, 25) as $other) {
            if ($other === $number) {
                continue;
            }

            $total += (float) ($pairScores[$number][$other] ?? $pairScores[$other][$number] ?? 0.0);
            $count++;
        }

        if ($count === 0) {
            return 0.5;
        }

        return $this->sigmoid($total / $count);
    }

    protected function sigmoid(float $value): float
    {
        return 1.0 / (1.0 + exp(-$value));
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

    protected function dynamicContextVariance(
        int $number,
        LotofacilConcurso $concursoBase,
        int $quantidadeDezenas
    ): float {
        $seed = (((int) $concursoBase->concurso * 17) + ($number * 31) + ($quantidadeDezenas * 13)) % 100;

        return match (true) {
            $seed <= 8 => -0.018,
            $seed <= 22 => -0.010,
            $seed <= 68 => 0.0,
            $seed <= 86 => 0.010,
            default => 0.018,
        };
    }

    protected function extremeBiasPenalty(float $frequency, float $delay, float $cycle): float
    {
        $penalty = 0.0;

        if ($frequency >= 0.90 && $delay <= 0.10) {
            $penalty += 0.035;
        }

        if ($frequency <= 0.08 && $delay >= 0.92) {
            $penalty += 0.035;
        }

        if ($cycle <= 0.08 || $cycle >= 0.92) {
            $penalty += 0.020;
        }

        return $penalty;
    }

    protected function temperature(float $frequency, float $delay, float $cycle): string
    {
        $temperatureScore = ($frequency * 0.55) + ((1.0 - $delay) * 0.25) + ($cycle * 0.20);

        if ($temperatureScore >= 0.66) {
            return 'hot';
        }

        if ($temperatureScore <= 0.38) {
            return 'cold';
        }

        return 'neutral';
    }

    protected function temperatureQuotas(int $quantidadeDezenas): array
    {
        return match ($quantidadeDezenas) {
            16 => ['hot' => 6, 'neutral' => 7, 'cold' => 3],
            17 => ['hot' => 7, 'neutral' => 7, 'cold' => 3],
            18 => ['hot' => 7, 'neutral' => 8, 'cold' => 3],
            19 => ['hot' => 8, 'neutral' => 8, 'cold' => 3],
            20 => ['hot' => 8, 'neutral' => 9, 'cold' => 3],
            default => ['hot' => 7, 'neutral' => 8, 'cold' => 3],
        };
    }

    protected function lineLimits(int $quantidadeDezenas, bool $relaxed = false): array
    {
        $max = match ($quantidadeDezenas) {
            16 => 4,
            17 => 5,
            18 => 5,
            19 => 5,
            20 => 5,
            default => 5,
        };

        if ($relaxed) {
            $max++;
        }

        return [
            'max' => [1 => $max, 2 => $max, 3 => $max, 4 => $max, 5 => $max],
        ];
    }

    protected function zoneLimits(int $quantidadeDezenas, bool $relaxed = false): array
    {
        $extra = $relaxed ? 1 : 0;

        return match ($quantidadeDezenas) {
            16 => ['max' => [1 => 7 + $extra, 2 => 8 + $extra, 3 => 7 + $extra]],
            17 => ['max' => [1 => 7 + $extra, 2 => 8 + $extra, 3 => 7 + $extra]],
            18 => ['max' => [1 => 8 + $extra, 2 => 9 + $extra, 3 => 8 + $extra]],
            19 => ['max' => [1 => 8 + $extra, 2 => 9 + $extra, 3 => 8 + $extra]],
            20 => ['max' => [1 => 9 + $extra, 2 => 10 + $extra, 3 => 9 + $extra]],
            default => ['max' => [1 => 8 + $extra, 2 => 9 + $extra, 3 => 8 + $extra]],
        };
    }

    protected function temperatureLimits(int $quantidadeDezenas, bool $relaxed = false): array
    {
        $extra = $relaxed ? 1 : 0;

        return match ($quantidadeDezenas) {
            16 => ['max' => ['hot' => 8 + $extra, 'neutral' => 10 + $extra, 'cold' => 5 + $extra]],
            17 => ['max' => ['hot' => 9 + $extra, 'neutral' => 10 + $extra, 'cold' => 5 + $extra]],
            18 => ['max' => ['hot' => 9 + $extra, 'neutral' => 11 + $extra, 'cold' => 6 + $extra]],
            19 => ['max' => ['hot' => 10 + $extra, 'neutral' => 11 + $extra, 'cold' => 6 + $extra]],
            20 => ['max' => ['hot' => 10 + $extra, 'neutral' => 12 + $extra, 'cold' => 7 + $extra]],
            default => ['max' => ['hot' => 9 + $extra, 'neutral' => 11 + $extra, 'cold' => 6 + $extra]],
        };
    }

    protected function rotateRankedPool(array $ranked, LotofacilConcurso $concursoBase, int $quantidadeDezenas): array
    {
        if (empty($ranked)) {
            return [];
        }

        $top = array_slice($ranked, 0, min(12, count($ranked)));
        $rest = array_slice($ranked, count($top));

        if (empty($rest)) {
            return $top;
        }

        $rotation = ((int) $concursoBase->concurso + ($quantidadeDezenas * 3)) % count($rest);
        $rest = array_merge(array_slice($rest, $rotation), array_slice($rest, 0, $rotation));

        return array_merge($top, $rest);
    }

    protected function weakestSelectedNumber(array $selected, array $numberScores): ?int
    {
        if (empty($selected)) {
            return null;
        }

        usort($selected, function (int $a, int $b) use ($numberScores): int {
            return ($numberScores[$a]['score'] ?? 0.0) <=> ($numberScores[$b]['score'] ?? 0.0);
        });

        return (int) $selected[0];
    }

    protected function selectionProfile(array $selected, array $numberScores): array
    {
        $profile = [
            'hot' => 0,
            'neutral' => 0,
            'cold' => 0,
            'lines' => [],
            'zones' => [],
        ];

        foreach ($selected as $number) {
            $temperature = $numberScores[(int) $number]['temperature'] ?? 'neutral';
            $line = $this->line((int) $number);
            $zone = $this->zone((int) $number);

            $profile[$temperature]++;
            $profile['lines'][$line] = ($profile['lines'][$line] ?? 0) + 1;
            $profile['zones'][$zone] = ($profile['zones'][$zone] ?? 0) + 1;
        }

        return $profile;
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

    protected function isEdge(int $number): bool
    {
        return in_array($number, [
            1, 2, 3, 4, 5,
            6, 10,
            11, 15,
            16, 20,
            21, 22, 23, 24, 25,
        ], true);
    }

    protected function extractNumbers(LotofacilConcurso $concurso): array
    {
        $numbers = [];

        for ($i = 1; $i <= 15; $i++) {
            $numbers[] = (int) $concurso->{'bola' . $i};
        }

        sort($numbers);

        return $numbers;
    }
}
