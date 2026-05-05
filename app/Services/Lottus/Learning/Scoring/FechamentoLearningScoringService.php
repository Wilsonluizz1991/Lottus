<?php

namespace App\Services\Lottus\Learning\Scoring;

use App\Models\LotofacilConcurso;
use Illuminate\Support\Collection;

class FechamentoLearningScoringService
{
    public function scoreBase(
        array $base,
        int $quantidadeDezenas,
        Collection $historico,
        LotofacilConcurso $concursoBase
    ): array {
        $base = $this->normalizeNumbers($base);
        $draws = $this->extractHistoricalDraws($historico, $concursoBase);

        if (count($base) !== $quantidadeDezenas || count($draws) < 80) {
            return $this->emptyScore($base);
        }

        $windows = [
            'window_40' => array_slice($draws, -40),
            'window_80' => array_slice($draws, -80),
            'window_160' => array_slice($draws, -160),
            'window_total' => $draws,
        ];

        $windowScores = [];

        foreach ($windows as $window => $windowDraws) {
            $windowScores[$window] = $this->scoreWindow($base, $windowDraws);
        }

        $recentTrend = $this->recentTrendScore($base, $draws);
        $stability = $this->stabilityScore($windowScores);
        $floorProtection = $this->floorProtectionScore($windowScores);
        $ceilingPotential = $this->ceilingPotentialScore($windowScores);
        $volatilityPenalty = $this->volatilityPenalty($windowScores);
        $structuralEntropy = $this->structuralEntropyScore($base);

        $finalScore =
            (($windowScores['window_40']['avg_hits'] ?? 0.0) * 0.16) +
            (($windowScores['window_80']['avg_hits'] ?? 0.0) * 0.20) +
            (($windowScores['window_160']['avg_hits'] ?? 0.0) * 0.22) +
            (($windowScores['window_total']['avg_hits'] ?? 0.0) * 0.10) +
            ($recentTrend * 0.10) +
            ($stability * 0.08) +
            ($floorProtection * 0.07) +
            ($ceilingPotential * 0.05) +
            ($structuralEntropy * 0.04) -
            ($volatilityPenalty * 0.08);

        return [
            'base' => $base,
            'final_score' => round($finalScore, 8),
            'recent_trend' => round($recentTrend, 8),
            'stability' => round($stability, 8),
            'floor_protection' => round($floorProtection, 8),
            'ceiling_potential' => round($ceilingPotential, 8),
            'volatility_penalty' => round($volatilityPenalty, 8),
            'structural_entropy' => round($structuralEntropy, 8),
            'windows' => $windowScores,
        ];
    }

    public function rankBases(
        array $bases,
        int $quantidadeDezenas,
        Collection $historico,
        LotofacilConcurso $concursoBase
    ): array {
        $ranked = [];

        foreach ($bases as $base) {
            $base = $this->normalizeNumbers($base);

            if (count($base) !== $quantidadeDezenas) {
                continue;
            }

            $score = $this->scoreBase(
                base: $base,
                quantidadeDezenas: $quantidadeDezenas,
                historico: $historico,
                concursoBase: $concursoBase
            );

            $ranked[] = [
                'base' => $base,
                'score' => $score,
                'final_score' => (float) ($score['final_score'] ?? 0.0),
            ];
        }

        usort($ranked, function (array $a, array $b): int {
            if (($a['final_score'] ?? 0.0) === ($b['final_score'] ?? 0.0)) {
                return implode('-', $a['base']) <=> implode('-', $b['base']);
            }

            return ($b['final_score'] ?? 0.0) <=> ($a['final_score'] ?? 0.0);
        });

        return $ranked;
    }

    protected function scoreWindow(array $base, array $draws): array
    {
        if (empty($draws)) {
            return [
                'avg_hits' => 0.0,
                'min_hits' => 0,
                'max_hits' => 0,
                'rate_10_plus' => 0.0,
                'rate_11_plus' => 0.0,
                'rate_12_plus' => 0.0,
                'rate_13_plus' => 0.0,
                'rate_14_plus' => 0.0,
                'stddev' => 0.0,
            ];
        }

        $hits = [];

        foreach ($draws as $draw) {
            $hits[] = count(array_intersect($base, $draw));
        }

        $count = count($hits);
        $avg = array_sum($hits) / max(1, $count);
        $min = min($hits);
        $max = max($hits);

        return [
            'avg_hits' => round($avg / 15, 8),
            'raw_avg_hits' => round($avg, 8),
            'min_hits' => $min,
            'max_hits' => $max,
            'rate_10_plus' => round($this->rateAtLeast($hits, 10), 8),
            'rate_11_plus' => round($this->rateAtLeast($hits, 11), 8),
            'rate_12_plus' => round($this->rateAtLeast($hits, 12), 8),
            'rate_13_plus' => round($this->rateAtLeast($hits, 13), 8),
            'rate_14_plus' => round($this->rateAtLeast($hits, 14), 8),
            'stddev' => round($this->stddev($hits), 8),
        ];
    }

    protected function recentTrendScore(array $base, array $draws): float
    {
        $recent = array_slice($draws, -20);
        $previous = array_slice($draws, -80, 60);

        if (empty($recent) || empty($previous)) {
            return 0.0;
        }

        $recentScore = $this->scoreWindow($base, $recent);
        $previousScore = $this->scoreWindow($base, $previous);

        $recentAvg = (float) ($recentScore['avg_hits'] ?? 0.0);
        $previousAvg = (float) ($previousScore['avg_hits'] ?? 0.0);

        return max(0.0, min(1.0, 0.5 + (($recentAvg - $previousAvg) * 2.5)));
    }

    protected function stabilityScore(array $windowScores): float
    {
        $averages = [];

        foreach ($windowScores as $score) {
            $averages[] = (float) ($score['avg_hits'] ?? 0.0);
        }

        if (empty($averages)) {
            return 0.0;
        }

        $stddev = $this->stddev($averages);

        return max(0.0, min(1.0, 1.0 - ($stddev * 4.0)));
    }

    protected function floorProtectionScore(array $windowScores): float
    {
        $rates = [];

        foreach ($windowScores as $score) {
            $rates[] = (float) ($score['rate_11_plus'] ?? 0.0);
        }

        if (empty($rates)) {
            return 0.0;
        }

        return max(0.0, min(1.0, array_sum($rates) / count($rates)));
    }

    protected function ceilingPotentialScore(array $windowScores): float
    {
        $score = 0.0;

        foreach ($windowScores as $windowScore) {
            $score += ((float) ($windowScore['rate_12_plus'] ?? 0.0) * 0.45);
            $score += ((float) ($windowScore['rate_13_plus'] ?? 0.0) * 0.35);
            $score += ((float) ($windowScore['rate_14_plus'] ?? 0.0) * 0.20);
        }

        return max(0.0, min(1.0, $score / max(1, count($windowScores))));
    }

    protected function volatilityPenalty(array $windowScores): float
    {
        $penalty = 0.0;

        foreach ($windowScores as $score) {
            $penalty += min(1.0, ((float) ($score['stddev'] ?? 0.0)) / 3.5);
        }

        return max(0.0, min(1.0, $penalty / max(1, count($windowScores))));
    }

    protected function structuralEntropyScore(array $base): float
    {
        $lines = [];
        $zones = [];
        $parity = [
            'even' => 0,
            'odd' => 0,
        ];

        foreach ($base as $number) {
            $line = (int) floor(($number - 1) / 5) + 1;
            $zone = $this->zone($number);

            $lines[$line] = ($lines[$line] ?? 0) + 1;
            $zones[$zone] = ($zones[$zone] ?? 0) + 1;

            if ($number % 2 === 0) {
                $parity['even']++;
            } else {
                $parity['odd']++;
            }
        }

        return max(0.0, min(1.0,
            ($this->entropyScore($lines, count($base)) * 0.45) +
            ($this->entropyScore($zones, count($base)) * 0.35) +
            ($this->entropyScore($parity, count($base)) * 0.20)
        ));
    }

    protected function extractHistoricalDraws(Collection $historico, LotofacilConcurso $concursoBase): array
    {
        return $historico
            ->filter(function ($concurso) use ($concursoBase) {
                $numero = is_array($concurso)
                    ? (int) ($concurso['concurso'] ?? 0)
                    : (int) ($concurso->concurso ?? 0);

                return $numero <= (int) $concursoBase->concurso;
            })
            ->sortBy(function ($concurso) {
                return is_array($concurso)
                    ? (int) ($concurso['concurso'] ?? 0)
                    : (int) ($concurso->concurso ?? 0);
            })
            ->map(fn ($concurso) => $this->extractNumbers($concurso))
            ->filter(fn (array $numbers) => count($numbers) === 15)
            ->values()
            ->all();
    }

    protected function extractNumbers($concurso): array
    {
        $numbers = [];

        for ($i = 1; $i <= 15; $i++) {
            $field = 'bola' . $i;

            if (is_array($concurso)) {
                if (isset($concurso[$field])) {
                    $numbers[] = (int) $concurso[$field];
                }

                continue;
            }

            if (isset($concurso->{$field})) {
                $numbers[] = (int) $concurso->{$field};
            }
        }

        return $this->normalizeNumbers($numbers);
    }

    protected function rateAtLeast(array $hits, int $target): float
    {
        if (empty($hits)) {
            return 0.0;
        }

        $count = 0;

        foreach ($hits as $hit) {
            if ($hit >= $target) {
                $count++;
            }
        }

        return $count / count($hits);
    }

    protected function stddev(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }

        $avg = array_sum($values) / count($values);
        $variance = 0.0;

        foreach ($values as $value) {
            $variance += (($value - $avg) ** 2);
        }

        return sqrt($variance / count($values));
    }

    protected function entropyScore(array $distribution, int $total): float
    {
        if ($total <= 0 || empty($distribution)) {
            return 0.0;
        }

        $entropy = 0.0;
        $groups = count($distribution);

        foreach ($distribution as $count) {
            $p = $count / $total;

            if ($p > 0) {
                $entropy -= $p * log($p);
            }
        }

        $maxEntropy = log(max(2, $groups));

        return max(0.0, min(1.0, $entropy / $maxEntropy));
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

    protected function emptyScore(array $base): array
    {
        return [
            'base' => $base,
            'final_score' => 0.0,
            'recent_trend' => 0.0,
            'stability' => 0.0,
            'floor_protection' => 0.0,
            'ceiling_potential' => 0.0,
            'volatility_penalty' => 1.0,
            'structural_entropy' => 0.0,
            'windows' => [],
        ];
    }

    protected function normalizeNumbers(array $numbers): array
    {
        $numbers = array_values(array_unique(array_map('intval', $numbers)));
        $numbers = array_values(array_filter($numbers, fn (int $number) => $number >= 1 && $number <= 25));
        sort($numbers);

        return $numbers;
    }
}