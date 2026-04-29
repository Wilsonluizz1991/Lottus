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

        $weights = config('lottus_fechamento.number_selection_weights', []);

        $numberScores = [];

        foreach (range(1, 25) as $number) {
            $frequency = (float) ($frequencyScores[$number] ?? 0.0);
            $delay = (float) ($delayScores[$number] ?? 0.0);
            $cycle = (float) ($cycleScores[$number] ?? 0.0);
            $correlation = $this->averageCorrelation($number, $pairScores);
            $lastDrawPresence = in_array($number, $lastDraw, true) ? 1.0 : 0.0;
            $cycleMissingPresence = in_array($number, $faltantes, true) ? 1.0 : 0.0;

            $score =
                ($frequency * (float) ($weights['frequency'] ?? 0.24)) +
                ($delay * (float) ($weights['delay'] ?? 0.20)) +
                ($cycle * (float) ($weights['cycle'] ?? 0.22)) +
                ($correlation * (float) ($weights['correlation'] ?? 0.24)) +
                ($lastDrawPresence * (float) ($weights['last_draw_presence'] ?? 0.10));

            if ($cycleMissingPresence > 0) {
                $score += 0.08;
            }

            if ($number >= 1 && $number <= 5) {
                $score += 0.010;
            }

            if ($number >= 21 && $number <= 25) {
                $score += 0.010;
            }

            $numberScores[$number] = round($score, 8);
        }

        arsort($numberScores);

        $selected = array_slice(array_keys($numberScores), 0, $quantidadeDezenas);
        $selected = array_values(array_unique(array_map('intval', $selected)));

        if (count($selected) < $quantidadeDezenas) {
            foreach (array_keys($numberScores) as $number) {
                if (count($selected) >= $quantidadeDezenas) {
                    break;
                }

                if (! in_array((int) $number, $selected, true)) {
                    $selected[] = (int) $number;
                }
            }
        }

        sort($selected);

        return $selected;
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
            return 0.0;
        }

        return $this->sigmoid($total / $count);
    }

    protected function sigmoid(float $value): float
    {
        return 1.0 / (1.0 + exp(-$value));
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