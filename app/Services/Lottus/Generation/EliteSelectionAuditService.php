<?php

namespace App\Services\Lottus\Generation;

use Illuminate\Support\Facades\Log;

class EliteSelectionAuditService
{
    public function audit(array $rankedGames, array $selectedGames, array $tuning, int $quantidade): void
    {
        try {
            $this->writeAudit($rankedGames, $selectedGames, $tuning, $quantidade);
        } catch (\Throwable) {
            // Auditoria e telemetria nao podem impedir a geracao comercial.
        }
    }

    protected function writeAudit(array $rankedGames, array $selectedGames, array $tuning, int $quantidade): void
    {
        if (! (bool) $this->tuningValue($tuning, 'elite_selection_audit.enabled', false)) {
            return;
        }

        if (empty($rankedGames)) {
            return;
        }

        $rawPool = array_values($rankedGames);

        usort($rawPool, function ($a, $b) {
            return $this->rawPreservationValue($b) <=> $this->rawPreservationValue($a);
        });

        $limit = (int) $this->tuningValue($tuning, 'elite_selection_audit.top_raw_limit', 10);
        $topRaw = array_slice($rawPool, 0, $limit);

        $rows = [];

        foreach ($topRaw as $index => $candidate) {
            $isSelected = $this->alreadySelected($candidate, $selectedGames);

            $rows[] = [
                'raw_position' => $index + 1,
                'selected' => $isSelected,
                'dezenas' => $candidate['dezenas'] ?? [],
                'raw_value' => $this->rawPreservationValue($candidate),
                'score' => (float) ($candidate['score'] ?? 0.0),
                'extreme_score' => (float) ($candidate['extreme_score'] ?? 0.0),
                'stat_score' => (float) ($candidate['stat_score'] ?? 0.0),
                'structure_score' => (float) ($candidate['structure_score'] ?? 0.0),
                'repeat_count' => (int) ($candidate['repetidas_ultimo_concurso'] ?? 0),
                'cycle_hits' => (int) ($candidate['cycle_hits'] ?? 0),
                'soma' => (int) ($candidate['soma'] ?? 0),
                'impares' => (int) ($candidate['impares'] ?? 0),
                'sequencia_maxima' => (int) ($candidate['analise']['sequencia_maxima'] ?? 0),
                'cluster_strength' => (float) ($candidate['analise']['cluster_strength'] ?? 0.0),
                'max_overlap_selected' => $this->maxOverlapWithSelected($candidate, $selectedGames),
                'clone_penalty' => $this->clonePenalty($candidate, $selectedGames, $tuning),
                'diversity_value' => $this->diversityValue($candidate, $selectedGames, $tuning),
                'coverage_value' => $this->coverageValue($candidate, $selectedGames),
                'core_bonus' => $this->coreBonus($candidate, $selectedGames, $tuning),
                'portfolio_value' => $this->portfolioValue($candidate, $selectedGames, $tuning),
            ];
        }

        Log::channel($this->tuningValue($tuning, 'elite_selection_audit.log_channel', 'single'))->info(
            'Lottus Elite Selection Audit',
            [
                'quantidade' => $quantidade,
                'selected_count' => count($selectedGames),
                'top_raw_limit' => $limit,
                'selected_games' => array_map(fn ($game) => $game['dezenas'] ?? [], $selectedGames),
                'top_raw_candidates' => $rows,
            ]
        );
    }

    protected function portfolioValue(array $candidate, array $selected, array $tuning): float
    {
        $rawValue = $this->rawPreservationValue($candidate);
        $diversityValue = $this->diversityValue($candidate, $selected, $tuning);
        $coverageValue = $this->coverageValue($candidate, $selected);
        $clonePenalty = $this->clonePenalty($candidate, $selected, $tuning);
        $coreBonus = $this->coreBonus($candidate, $selected, $tuning);

        $rawWeight = (float) $this->tuningValue($tuning, 'portfolio_expansion.raw_weight', 0.985);
        $diversityWeight = (float) $this->tuningValue($tuning, 'portfolio_expansion.diversity_weight', 0.004);
        $coverageWeight = (float) $this->tuningValue($tuning, 'portfolio_expansion.coverage_weight', 0.003);
        $coreBonusMultiplier = (float) $this->tuningValue($tuning, 'portfolio_expansion.core_bonus_multiplier', 1.30);

        return ($rawValue * $rawWeight)
            + ($diversityValue * $diversityWeight)
            + ($coverageValue * $coverageWeight)
            + ($coreBonus * $coreBonusMultiplier)
            - $clonePenalty;
    }

    protected function rawPreservationValue(array $candidate): float
    {
        $score = (float) ($candidate['score'] ?? 0.0);
        $extremeScore = (float) ($candidate['extreme_score'] ?? 0.0);
        $statScore = (float) ($candidate['stat_score'] ?? 0.0);
        $structureScore = (float) ($candidate['structure_score'] ?? 0.0);
        $repeatCount = (int) ($candidate['repetidas_ultimo_concurso'] ?? 0);
        $cycleHits = (int) ($candidate['cycle_hits'] ?? 0);
        $sum = (int) ($candidate['soma'] ?? 0);
        $oddCount = (int) ($candidate['impares'] ?? 0);
        $sequence = (int) ($candidate['analise']['sequencia_maxima'] ?? 0);
        $clusterStrength = (float) ($candidate['analise']['cluster_strength'] ?? 0.0);
        $frameCount = (int) ($candidate['analise']['moldura'] ?? 0);

        $value = 0.0;

        $value += $score * 100;
        $value += $extremeScore * 42;
        $value += $statScore * 38;
        $value += $structureScore * 2;

        if ($repeatCount >= 8 && $repeatCount <= 11) {
            $value += 88;
        } elseif ($repeatCount === 7 || $repeatCount === 12) {
            $value += 52;
        } elseif ($repeatCount === 6 || $repeatCount === 13) {
            $value += 20;
        }

        if ($cycleHits >= 4) {
            $value += 50;
        } elseif ($cycleHits === 3) {
            $value += 42;
        } elseif ($cycleHits === 2) {
            $value += 32;
        } elseif ($cycleHits === 1) {
            $value += 22;
        }

        if ($sum >= 160 && $sum <= 225) {
            $value += 8;
        } elseif ($sum >= 150 && $sum <= 235) {
            $value += 4;
        }

        if ($oddCount >= 6 && $oddCount <= 9) {
            $value += 8;
        } elseif ($oddCount === 5 || $oddCount === 10) {
            $value += 4;
        }

        if ($sequence >= 3 && $sequence <= 6) {
            $value += 6;
        } elseif ($sequence === 2 || $sequence === 7 || $sequence === 8) {
            $value += 3;
        }

        if ($clusterStrength >= 9) {
            $value += 8;
        } elseif ($clusterStrength >= 7) {
            $value += 5;
        } elseif ($clusterStrength >= 5) {
            $value += 2;
        }

        if ($frameCount >= 7 && $frameCount <= 12) {
            $value += 3;
        }

        return $value;
    }

    protected function coreBonus(array $candidate, array $selected, array $tuning): float
    {
        $maxOverlap = $this->maxOverlapWithSelected($candidate, $selected);

        if ($maxOverlap >= 14) {
            return (float) $this->tuningValue($tuning, 'core_bonus.overlap_14', 380.0);
        }

        if ($maxOverlap === 13) {
            return (float) $this->tuningValue($tuning, 'core_bonus.overlap_13', 240.0);
        }

        if ($maxOverlap === 12) {
            return (float) $this->tuningValue($tuning, 'core_bonus.overlap_12', 120.0);
        }

        if ($maxOverlap === 11) {
            return (float) $this->tuningValue($tuning, 'core_bonus.overlap_11', 35.0);
        }

        if ($maxOverlap === 10) {
            return (float) $this->tuningValue($tuning, 'core_bonus.overlap_10', 0.0);
        }

        return 0.0;
    }

    protected function diversityValue(array $candidate, array $selected, array $tuning): float
    {
        if (empty($selected)) {
            return 0.0;
        }

        $candidateNumbers = $candidate['dezenas'] ?? [];
        $totalDistance = 0.0;
        $comparisons = 0;

        foreach ($selected as $selectedGame) {
            $selectedNumbers = $selectedGame['dezenas'] ?? [];
            $intersection = count(array_intersect($candidateNumbers, $selectedNumbers));
            $distance = 15 - $intersection;

            $totalDistance += $distance;
            $comparisons++;
        }

        if ($comparisons === 0) {
            return 0.0;
        }

        $averageDistance = $totalDistance / $comparisons;

        return $averageDistance * (float) $this->tuningValue($tuning, 'diversity.average_distance_multiplier', 7.0);
    }

    protected function coverageValue(array $candidate, array $selected): float
    {
        $candidateNumbers = $candidate['dezenas'] ?? [];
        $covered = [];

        foreach ($selected as $selectedGame) {
            foreach (($selectedGame['dezenas'] ?? []) as $number) {
                $covered[$number] = true;
            }
        }

        $newNumbers = 0;

        foreach ($candidateNumbers as $number) {
            if (! isset($covered[$number])) {
                $newNumbers++;
            }
        }

        $value = $newNumbers * 5;

        $repeatCount = (int) ($candidate['repetidas_ultimo_concurso'] ?? 0);
        $cycleHits = (int) ($candidate['cycle_hits'] ?? 0);

        if ($repeatCount >= 7 && $repeatCount <= 12) {
            $value += 4;
        }

        if ($cycleHits >= 1) {
            $value += $cycleHits * 2;
        }

        return $value;
    }

    protected function clonePenalty(array $candidate, array $selected, array $tuning): float
    {
        if (empty($selected)) {
            return 0.0;
        }

        $candidateNumbers = $candidate['dezenas'] ?? [];
        $penalty = 0.0;

        foreach ($selected as $selectedGame) {
            $selectedNumbers = $selectedGame['dezenas'] ?? [];
            $intersection = count(array_intersect($candidateNumbers, $selectedNumbers));

            if ($intersection === 15) {
                $penalty += (float) $this->tuningValue($tuning, 'clone_penalty.overlap_15', 999.0);
            } elseif ($intersection >= 14) {
                $penalty += (float) $this->tuningValue($tuning, 'clone_penalty.overlap_14', 2.0);
            } elseif ($intersection === 13) {
                $penalty += (float) $this->tuningValue($tuning, 'clone_penalty.overlap_13', 0.7);
            } elseif ($intersection <= (int) $this->tuningValue($tuning, 'clone_penalty.low_overlap_limit', 6)) {
                $penalty += (float) $this->tuningValue($tuning, 'clone_penalty.low_overlap_penalty', 8.0);
            }
        }

        return $penalty;
    }

    protected function maxOverlapWithSelected(array $candidate, array $selected): int
    {
        $candidateNumbers = $candidate['dezenas'] ?? [];
        $maxOverlap = 0;

        foreach ($selected as $selectedGame) {
            $selectedNumbers = $selectedGame['dezenas'] ?? [];
            $maxOverlap = max($maxOverlap, count(array_intersect($candidateNumbers, $selectedNumbers)));
        }

        return $maxOverlap;
    }

    protected function alreadySelected(array $candidate, array $selected): bool
    {
        $candidateKey = $this->candidateKey($candidate);

        foreach ($selected as $game) {
            if ($candidateKey === $this->candidateKey($game)) {
                return true;
            }
        }

        return false;
    }

    protected function candidateKey(array $candidate): string
    {
        $dezenas = $candidate['dezenas'] ?? [];

        sort($dezenas);

        return implode('-', $dezenas);
    }

    protected function tuningValue(array $tuning, string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $tuning;

        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
