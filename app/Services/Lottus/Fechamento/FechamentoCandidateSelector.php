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

            $score =
                ($frequency * 0.31) +
                ($delay * 0.25) +
                ($cycle * 0.26) +
                ($correlation * 0.10) +
                ($lastDrawPresence * 0.08);

            if ($cycleMissingPresence > 0) {
                $score += 0.095;
            }

            if ($this->isEdge($number)) {
                $score += 0.030;
            }

            if ($this->isCoreAnchor($number)) {
                $score += 0.040;
            }

            if ($number >= 1 && $number <= 5) {
                $score += 0.018;
            }

            if ($number >= 21 && $number <= 25) {
                $score += 0.022;
            }

            $numberScores[$number] = [
                'number' => $number,
                'score' => round($score, 8),
                'frequency' => $frequency,
                'delay' => $delay,
                'cycle' => $cycle,
                'correlation' => $correlation,
                'last_draw_presence' => $lastDrawPresence,
                'cycle_missing_presence' => $cycleMissingPresence,
                'line' => $this->line($number),
                'zone' => $this->zone($number),
                'is_edge' => $this->isEdge($number),
                'is_core_anchor' => $this->isCoreAnchor($number),
            ];
        }

        $selected = $this->selectByControlledExclusion(
            quantidadeDezenas: $quantidadeDezenas,
            numberScores: $numberScores,
            lastDraw: $lastDraw,
            faltantes: $faltantes
        );

        $selected = $this->rescueCriticalNumbers(
            selected: $selected,
            numberScores: $numberScores,
            quantidadeDezenas: $quantidadeDezenas
        );

        if (count($selected) !== $quantidadeDezenas) {
            $selected = $this->fallbackTopSelection($numberScores, $quantidadeDezenas);
        }

        sort($selected);

        return $selected;
    }

    protected function selectByControlledExclusion(
        int $quantidadeDezenas,
        array $numberScores,
        array $lastDraw,
        array $faltantes
    ): array {
        $targetOmitted = 25 - $quantidadeDezenas;
        $selected = range(1, 25);
        $omitted = [];

        $candidates = array_values($numberScores);

        usort($candidates, function (array $a, array $b): int {
            $riskA = $this->exclusionRisk($a);
            $riskB = $this->exclusionRisk($b);

            if ($riskA === $riskB) {
                return $a['score'] <=> $b['score'];
            }

            return $riskA <=> $riskB;
        });

        foreach ($candidates as $candidate) {
            if (count($omitted) >= $targetOmitted) {
                break;
            }

            $number = (int) $candidate['number'];

            if (! $this->canOmitNumber(
                number: $number,
                selected: $selected,
                omitted: $omitted,
                targetOmitted: $targetOmitted,
                lastDraw: $lastDraw,
                faltantes: $faltantes,
                candidate: $candidate
            )) {
                continue;
            }

            $omitted[] = $number;
            $selected = array_values(array_diff($selected, [$number]));
        }

        if (count($omitted) < $targetOmitted) {
            foreach ($candidates as $candidate) {
                if (count($omitted) >= $targetOmitted) {
                    break;
                }

                $number = (int) $candidate['number'];

                if (in_array($number, $omitted, true)) {
                    continue;
                }

                if ($this->wouldBreakMinimumLineCoverage($number, $selected)) {
                    continue;
                }

                if ($this->isCoreAnchor($number)) {
                    continue;
                }

                $omitted[] = $number;
                $selected = array_values(array_diff($selected, [$number]));
            }
        }

        return array_values(array_unique(array_map('intval', $selected)));
    }

    protected function canOmitNumber(
        int $number,
        array $selected,
        array $omitted,
        int $targetOmitted,
        array $lastDraw,
        array $faltantes,
        array $candidate
    ): bool {
        if (in_array($number, $omitted, true)) {
            return false;
        }

        if ($this->wouldBreakMinimumLineCoverage($number, $selected)) {
            return false;
        }

        if ($this->wouldOverOmitLine($number, $omitted)) {
            return false;
        }

        if ($this->isCoreAnchor($number)) {
            return false;
        }

        if (($candidate['score'] ?? 0) >= 0.58) {
            return false;
        }

        if (in_array($number, $lastDraw, true)) {
            $selectedLastDraw = count(array_intersect($selected, $lastDraw));

            if ($selectedLastDraw <= 11) {
                return false;
            }
        }

        if (in_array($number, $faltantes, true)) {
            $selectedFaltantes = count(array_intersect($selected, $faltantes));

            if ($selectedFaltantes <= 4) {
                return false;
            }
        }

        if ($this->isEdge($number)) {
            $selectedEdges = count(array_filter(
                $selected,
                fn ($item) => $this->isEdge((int) $item)
            ));

            if ($selectedEdges <= 12) {
                return false;
            }
        }

        return true;
    }

    protected function rescueCriticalNumbers(
        array $selected,
        array $numberScores,
        int $quantidadeDezenas
    ): array {
        $selected = array_values(array_unique(array_map('intval', $selected)));

        $missing = array_filter(
            $numberScores,
            fn (array $item) => ! in_array($item['number'], $selected, true)
        );

        usort($missing, function (array $a, array $b): int {
            $riskA = $this->exclusionRisk($a);
            $riskB = $this->exclusionRisk($b);

            if ($riskA === $riskB) {
                return $b['score'] <=> $a['score'];
            }

            return $riskB <=> $riskA;
        });

        foreach ($missing as $candidate) {
            $number = (int) $candidate['number'];
            $risk = $this->exclusionRisk($candidate);

            if ($risk < 0.62 && ! $this->isCoreAnchor($number)) {
                continue;
            }

            if (in_array($number, $selected, true)) {
                continue;
            }

            $remove = $this->weakestRemovableNumber($selected, $numberScores);

            if ($remove === null) {
                continue;
            }

            $selected = array_values(array_diff($selected, [$remove]));
            $selected[] = $number;

            if (count($selected) > $quantidadeDezenas) {
                $selected = array_slice(array_values(array_unique($selected)), 0, $quantidadeDezenas);
            }
        }

        return array_values(array_unique(array_map('intval', $selected)));
    }

    protected function weakestRemovableNumber(array $selected, array $numberScores): ?int
    {
        $candidates = array_filter(
            $selected,
            fn ($number) => ! $this->isCoreAnchor((int) $number)
        );

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, function (int $a, int $b) use ($numberScores): int {
            $riskA = $this->exclusionRisk($numberScores[$a] ?? ['score' => 0]);
            $riskB = $this->exclusionRisk($numberScores[$b] ?? ['score' => 0]);

            if ($riskA === $riskB) {
                return ($numberScores[$a]['score'] ?? 0) <=> ($numberScores[$b]['score'] ?? 0);
            }

            return $riskA <=> $riskB;
        });

        foreach ($candidates as $candidate) {
            if (! $this->wouldBreakMinimumLineCoverage((int) $candidate, $selected)) {
                return (int) $candidate;
            }
        }

        return (int) ($candidates[0] ?? null);
    }

    protected function exclusionRisk(array $candidate): float
    {
        $risk = (float) ($candidate['score'] ?? 0.0);

        if (($candidate['last_draw_presence'] ?? 0) > 0) {
            $risk += 0.140;
        }

        if (($candidate['cycle_missing_presence'] ?? 0) > 0) {
            $risk += 0.130;
        }

        if (($candidate['is_edge'] ?? false) === true) {
            $risk += 0.085;
        }

        if (($candidate['is_core_anchor'] ?? false) === true) {
            $risk += 0.160;
        }

        if (($candidate['frequency'] ?? 0) >= 0.52 && ($candidate['cycle'] ?? 0) >= 0.48) {
            $risk += 0.095;
        }

        if (($candidate['delay'] ?? 0) >= 0.50 && ($candidate['cycle'] ?? 0) >= 0.46) {
            $risk += 0.080;
        }

        if (($candidate['correlation'] ?? 0) >= 0.64) {
            $risk += 0.045;
        }

        return round($risk, 8);
    }

    protected function wouldBreakMinimumLineCoverage(int $number, array $selected): bool
    {
        $line = $this->line($number);

        $lineCount = count(array_filter(
            $selected,
            fn ($item) => $this->line((int) $item) === $line
        ));

        return $lineCount <= 3;
    }

    protected function wouldOverOmitLine(int $number, array $omitted): bool
    {
        $line = $this->line($number);

        $omittedFromLine = count(array_filter(
            $omitted,
            fn ($item) => $this->line((int) $item) === $line
        ));

        return $omittedFromLine >= 2;
    }

    protected function fallbackTopSelection(array $numberScores, int $quantidadeDezenas): array
    {
        $candidates = array_values($numberScores);

        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);

        $selected = array_map(
            fn (array $item) => (int) $item['number'],
            array_slice($candidates, 0, $quantidadeDezenas)
        );

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

    protected function isCoreAnchor(int $number): bool
    {
        return in_array($number, [
            1, 2, 5,
            6, 9, 10,
            11, 12, 13, 14, 15,
            16, 17, 20,
            21, 22, 24, 25,
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