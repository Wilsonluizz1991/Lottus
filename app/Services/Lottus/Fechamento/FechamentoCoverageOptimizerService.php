<?php

namespace App\Services\Lottus\Fechamento;

class FechamentoCoverageOptimizerService
{
    public function optimize(
        array $scoredCombinations,
        int $quantidadeJogos,
        array $dezenasBase,
        array $numberScores = []
    ): array {
        if ($quantidadeJogos <= 0 || empty($scoredCombinations)) {
            return [];
        }

        $dezenasBase = $this->normalizeNumbers($dezenasBase);
        $quantidadeDezenas = count($dezenasBase);
        $quantidadeOmitidas = $quantidadeDezenas - 15;

        if ($quantidadeDezenas < 16 || $quantidadeDezenas > 20 || $quantidadeOmitidas < 1 || $quantidadeOmitidas > 5) {
            return [];
        }

        $pool = $this->normalizePool($scoredCombinations, $dezenasBase);

        if (empty($pool)) {
            return [];
        }

        usort($pool, fn (array $a, array $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        $pool = $this->prepareNormalizedScore($pool);
        $pool = $this->attachRawRank($pool);

        $windowSize = min(
            count($pool),
            max($quantidadeJogos * $this->windowMultiplier($quantidadeOmitidas), $this->minimumWindowSize($quantidadeOmitidas))
        );

        $workingPool = $quantidadeOmitidas >= 4 ? $pool : array_slice($pool, 0, $windowSize);
        $numberProfiles = $this->normalizeNumberProfiles($numberScores);

        $omissionModel = $this->buildAdaptiveOmissionModel(
            workingPool: $workingPool,
            dezenasBase: $dezenasBase,
            quantidadeOmitidas: $quantidadeOmitidas,
            numberProfiles: $numberProfiles
        );

        $ranked = [];

        foreach ($workingPool as $candidate) {
            $candidate['_adaptive_omission_score'] = $this->adaptiveOmissionPortfolioValue(
                candidate: $candidate,
                omissionModel: $omissionModel,
                numberProfiles: $numberProfiles,
                quantidadeOmitidas: $quantidadeOmitidas
            );

            $candidate['_raw_band_score'] = $this->rawBandCandidateScore($candidate, $quantidadeOmitidas);
            $candidate['_is_raw_rank_protected'] = $this->isRawRankProtectedCandidate($candidate, $quantidadeOmitidas);

            $ranked[] = $candidate;
        }

        usort($ranked, function (array $a, array $b): int {
            if (($a['_adaptive_omission_score'] ?? 0) === ($b['_adaptive_omission_score'] ?? 0)) {
                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            }

            return ($b['_adaptive_omission_score'] ?? 0) <=> ($a['_adaptive_omission_score'] ?? 0);
        });

        $selected = [];
        $seen = [];
        $coverage = $this->emptyCoverageState($dezenasBase);

        $this->selectTopRawCore(
            ranked: $ranked,
            selected: $selected,
            seen: $seen,
            coverage: $coverage,
            quantidadeJogos: $quantidadeJogos,
            quantidadeOmitidas: $quantidadeOmitidas
        );

        $this->selectRawRankStratifiedCore(
            ranked: $ranked,
            selected: $selected,
            seen: $seen,
            coverage: $coverage,
            quantidadeJogos: $quantidadeJogos,
            quantidadeOmitidas: $quantidadeOmitidas
        );

        $this->selectAdaptiveFill(
            ranked: $ranked,
            selected: $selected,
            seen: $seen,
            coverage: $coverage,
            omissionModel: $omissionModel,
            quantidadeJogos: $quantidadeJogos,
            quantidadeOmitidas: $quantidadeOmitidas,
            strict: true
        );

        if (count($selected) < $quantidadeJogos) {
            $this->selectAdaptiveFill(
                ranked: $ranked,
                selected: $selected,
                seen: $seen,
                coverage: $coverage,
                omissionModel: $omissionModel,
                quantidadeJogos: $quantidadeJogos,
                quantidadeOmitidas: $quantidadeOmitidas,
                strict: false
            );
        }

        if (count($selected) < $quantidadeJogos) {
            foreach ($ranked as $candidate) {
                if (count($selected) >= $quantidadeJogos) {
                    break;
                }

                $this->addCandidate(
                    selected: $selected,
                    seen: $seen,
                    coverage: $coverage,
                    candidate: $candidate
                );
            }
        }

        if (count($selected) < $quantidadeJogos) {
            return [];
        }

        foreach ($selected as $index => &$candidate) {
            $candidate['portfolio_order'] = $index + 1;
            $candidate['coverage_optimized'] = true;
            $candidate['adaptive_omission_optimized'] = true;
            $candidate['raw_rank_stratified_optimized'] = true;
            $candidate['raw_preservation_optimized'] = true;
            $candidate['quantidade_omitidas'] = $quantidadeOmitidas;

            unset($candidate['_adaptive_omission_score']);
            unset($candidate['_raw_band_score']);
            unset($candidate['_is_raw_rank_protected']);
        }

        unset($candidate);

        usort($selected, function (array $a, array $b): int {
            $aProtected = ! empty($a['raw_rank_protected']) || ! empty($a['raw_top_protected']);
            $bProtected = ! empty($b['raw_rank_protected']) || ! empty($b['raw_top_protected']);

            if ($aProtected !== $bProtected) {
                return $aProtected ? -1 : 1;
            }

            if (($a['portfolio_order'] ?? 0) !== ($b['portfolio_order'] ?? 0)) {
                return ($a['portfolio_order'] ?? 0) <=> ($b['portfolio_order'] ?? 0);
            }

            $scoreComparison = ($b['score'] ?? 0) <=> ($a['score'] ?? 0);

            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            return ($b['normalized_score'] ?? 0) <=> ($a['normalized_score'] ?? 0);
        });

        return array_slice($selected, 0, $quantidadeJogos);
    }

    protected function attachRawRank(array $pool): array
    {
        foreach ($pool as $index => &$candidate) {
            $candidate['raw_rank'] = $index + 1;
        }

        unset($candidate);

        return $pool;
    }

    protected function selectTopRawCore(
        array $ranked,
        array &$selected,
        array &$seen,
        array &$coverage,
        int $quantidadeJogos,
        int $quantidadeOmitidas
    ): void {
        $limit = match ($quantidadeOmitidas) {
            1 => max(6, (int) floor($quantidadeJogos * 0.32)),
            2 => max(12, (int) floor($quantidadeJogos * 0.48)),
            3 => max(24, (int) floor($quantidadeJogos * 0.75)),
            4 => max(34, (int) floor($quantidadeJogos * 0.70)),
            5 => max(44, (int) floor($quantidadeJogos * 0.68)),
            default => max(24, (int) floor($quantidadeJogos * 0.70)),
        };

        $rawSorted = $ranked;

        usort($rawSorted, function (array $a, array $b): int {
            return ((int) ($a['raw_rank'] ?? PHP_INT_MAX)) <=> ((int) ($b['raw_rank'] ?? PHP_INT_MAX));
        });

        foreach ($rawSorted as $candidate) {
            if (count($selected) >= $limit || count($selected) >= $quantidadeJogos) {
                break;
            }

            if ((int) ($candidate['raw_rank'] ?? PHP_INT_MAX) > $this->topRawDirectWindow($quantidadeOmitidas)) {
                break;
            }

            $candidate['raw_top_protected'] = true;
            $candidate['raw_rank_protected'] = true;

            $this->addCandidate(
                selected: $selected,
                seen: $seen,
                coverage: $coverage,
                candidate: $candidate
            );
        }
    }

    protected function selectRawRankStratifiedCore(
        array $ranked,
        array &$selected,
        array &$seen,
        array &$coverage,
        int $quantidadeJogos,
        int $quantidadeOmitidas
    ): void {
        $window = $this->rawRankPreservationWindow($quantidadeOmitidas);
        $target = min(
            $quantidadeJogos,
            max(0, $this->rawRankPreservationCount($quantidadeJogos, $quantidadeOmitidas) - count($selected))
        );

        if ($target <= 0 || $window <= 0) {
            return;
        }

        $eligible = array_values(array_filter(
            $ranked,
            fn (array $candidate): bool => (int) ($candidate['raw_rank'] ?? PHP_INT_MAX) <= $window
        ));

        if (empty($eligible)) {
            return;
        }

        usort($eligible, function (array $a, array $b): int {
            if (($a['_raw_band_score'] ?? 0.0) !== ($b['_raw_band_score'] ?? 0.0)) {
                return ($b['_raw_band_score'] ?? 0.0) <=> ($a['_raw_band_score'] ?? 0.0);
            }

            return ((int) ($a['raw_rank'] ?? PHP_INT_MAX)) <=> ((int) ($b['raw_rank'] ?? PHP_INT_MAX));
        });

        $eliteTarget = max(
            1,
            min($target, $this->rawElitePreservationCount($quantidadeJogos, $quantidadeOmitidas))
        );

        foreach ($eligible as $candidate) {
            if (count($selected) >= $quantidadeJogos || $eliteTarget <= 0 || $target <= 0) {
                break;
            }

            $key = $this->candidateKey($candidate['dezenas'] ?? []);

            if (isset($seen[$key])) {
                continue;
            }

            $candidate['raw_rank_protected'] = true;
            $candidate['raw_elite_preserved'] = true;

            $this->addCandidate(
                selected: $selected,
                seen: $seen,
                coverage: $coverage,
                candidate: $candidate
            );

            $eliteTarget--;
            $target--;
        }

        if ($target <= 0) {
            return;
        }

        $bandSize = max(1, (int) ceil($window / max(1, $target)));
        $bands = [];

        foreach ($eligible as $candidate) {
            $rank = (int) ($candidate['raw_rank'] ?? PHP_INT_MAX);
            $band = (int) floor(($rank - 1) / $bandSize);
            $bands[$band][] = $candidate;
        }

        ksort($bands);

        foreach ($bands as $bandCandidates) {
            if (count($selected) >= $quantidadeJogos || $target <= 0) {
                break;
            }

            usort($bandCandidates, function (array $a, array $b): int {
                if (($a['_raw_band_score'] ?? 0.0) !== ($b['_raw_band_score'] ?? 0.0)) {
                    return ($b['_raw_band_score'] ?? 0.0) <=> ($a['_raw_band_score'] ?? 0.0);
                }

                return ((int) ($a['raw_rank'] ?? PHP_INT_MAX)) <=> ((int) ($b['raw_rank'] ?? PHP_INT_MAX));
            });

            foreach ($bandCandidates as $candidate) {
                $key = $this->candidateKey($candidate['dezenas'] ?? []);

                if (isset($seen[$key])) {
                    continue;
                }

                $candidate['raw_rank_protected'] = true;
                $candidate['raw_rank_band'] = (int) floor(((int) ($candidate['raw_rank'] ?? 1) - 1) / $bandSize) + 1;

                $this->addCandidate(
                    selected: $selected,
                    seen: $seen,
                    coverage: $coverage,
                    candidate: $candidate
                );

                $target--;
                break;
            }
        }
    }

    protected function selectAdaptiveFill(
        array $ranked,
        array &$selected,
        array &$seen,
        array &$coverage,
        array $omissionModel,
        int $quantidadeJogos,
        int $quantidadeOmitidas,
        bool $strict
    ): void {
        foreach ($ranked as $candidate) {
            if (count($selected) >= $quantidadeJogos) {
                break;
            }

            $key = $this->candidateKey($candidate['dezenas'] ?? []);

            if (isset($seen[$key])) {
                continue;
            }

            if ($strict && ! $this->canAcceptAdaptiveCandidate(
                candidate: $candidate,
                selected: $selected,
                omissionModel: $omissionModel,
                quantidadeJogos: $quantidadeJogos,
                quantidadeOmitidas: $quantidadeOmitidas
            )) {
                continue;
            }

            $this->addCandidate(
                selected: $selected,
                seen: $seen,
                coverage: $coverage,
                candidate: $candidate
            );
        }
    }

    protected function rawBandCandidateScore(array $candidate, int $quantidadeOmitidas): float
    {
        $score = 0.0;
        $rawRank = (int) ($candidate['raw_rank'] ?? PHP_INT_MAX);
        $normalizedScore = (float) ($candidate['normalized_score'] ?? 0.0);
        $baseScore = (float) ($candidate['score'] ?? 0.0);
        $correlationQuality = (float) ($candidate['correlation_quality'] ?? 0.0);
        $survivalQuality = (float) ($candidate['survival_quality'] ?? 0.0);
        $explosionQuality = (float) ($candidate['explosion_quality'] ?? 0.0);
        $structureQuality = (float) ($candidate['structure_quality'] ?? 0.0);
        $repeatCount = (int) ($candidate['repetidas_ultimo_concurso'] ?? 0);
        $sequence = (int) ($candidate['sequencia_maxima'] ?? 0);
        $sum = (int) ($candidate['soma'] ?? 0);
        $oddCount = (int) ($candidate['impares'] ?? 0);

        $score += $normalizedScore * 280.0;
        $score += $baseScore * 0.04;
        $score += $correlationQuality * 180.0;
        $score += $survivalQuality * 90.0;
        $score += $explosionQuality * 160.0;
        $score += $structureQuality * 35.0;
        $score += max(0, $this->rawRankPreservationWindow($quantidadeOmitidas) - $rawRank) * 0.08;

        if ($repeatCount >= 7 && $repeatCount <= 10) {
            $score += 42.0;
        }

        if ($sequence >= 4 && $sequence <= 9) {
            $score += 22.0;
        }

        if ($sum >= 165 && $sum <= 235) {
            $score += 20.0;
        }

        if ($oddCount >= 6 && $oddCount <= 10) {
            $score += 14.0;
        }

        return $score;
    }

    protected function isRawRankProtectedCandidate(array $candidate, int $quantidadeOmitidas): bool
    {
        return (int) ($candidate['raw_rank'] ?? PHP_INT_MAX) <= $this->rawRankPreservationWindow($quantidadeOmitidas);
    }

    protected function topRawDirectWindow(int $quantidadeOmitidas): int
    {
        return match ($quantidadeOmitidas) {
            1 => 20,
            2 => 40,
            3 => 80,
            4 => 120,
            5 => 160,
            default => 80,
        };
    }

    protected function rawRankPreservationWindow(int $quantidadeOmitidas): int
    {
        return match ($quantidadeOmitidas) {
            1 => 120,
            2 => 260,
            3 => 520,
            4 => 950,
            5 => 1500,
            default => 520,
        };
    }

    protected function rawRankPreservationCount(int $quantidadeJogos, int $quantidadeOmitidas): int
    {
        return match ($quantidadeOmitidas) {
            1 => max(6, (int) floor($quantidadeJogos * 0.24)),
            2 => max(10, (int) floor($quantidadeJogos * 0.32)),
            3 => max(18, (int) floor($quantidadeJogos * 0.50)),
            4 => max(42, (int) floor($quantidadeJogos * 0.70)),
            5 => max(54, (int) floor($quantidadeJogos * 0.78)),
            default => max(18, (int) floor($quantidadeJogos * 0.50)),
        };
    }

    protected function rawElitePreservationCount(int $quantidadeJogos, int $quantidadeOmitidas): int
    {
        return match ($quantidadeOmitidas) {
            1 => max(2, (int) floor($quantidadeJogos * 0.12)),
            2 => max(4, (int) floor($quantidadeJogos * 0.16)),
            3 => max(8, (int) floor($quantidadeJogos * 0.24)),
            4 => max(14, (int) floor($quantidadeJogos * 0.24)),
            5 => max(18, (int) floor($quantidadeJogos * 0.24)),
            default => max(8, (int) floor($quantidadeJogos * 0.24)),
        };
    }

    protected function normalizePool(array $scoredCombinations, array $dezenasBase): array
    {
        $pool = [];
        $seen = [];

        foreach ($scoredCombinations as $candidate) {
            $game = $candidate['dezenas'] ?? $candidate;
            $game = $this->normalizeNumbers($game);

            if (count($game) !== 15) {
                continue;
            }

            $key = $this->candidateKey($game);

            if (isset($seen[$key])) {
                continue;
            }

            $omitted = array_values(array_diff($dezenasBase, $game));
            sort($omitted);

            if (count($omitted) !== count($dezenasBase) - 15) {
                continue;
            }

            $candidate['dezenas'] = $game;
            $candidate['omitted_dezenas'] = $omitted;
            $candidate['omitted_key'] = $this->candidateKey($omitted);

            $pool[] = $candidate;
            $seen[$key] = true;
        }

        return $pool;
    }

    protected function prepareNormalizedScore(array $pool): array
    {
        $scores = array_map(
            fn ($candidate) => (float) ($candidate['score'] ?? 0.0),
            $pool
        );

        $min = min($scores);
        $max = max($scores);

        foreach ($pool as &$candidate) {
            $score = (float) ($candidate['score'] ?? 0.0);

            if ($max <= $min) {
                $candidate['normalized_score'] = 1.0;
            } else {
                $candidate['normalized_score'] = ($score - $min) / ($max - $min);
            }
        }

        unset($candidate);

        return $pool;
    }

    protected function buildAdaptiveOmissionModel(
        array $workingPool,
        array $dezenasBase,
        int $quantidadeOmitidas,
        array $numberProfiles
    ): array {
        $sourceSize = min(
            count($workingPool),
            max(160, 80 * $quantidadeOmitidas)
        );

        $source = array_slice($workingPool, 0, $sourceSize);

        $numberKeepFrequency = array_fill_keys($dezenasBase, 0.0);
        $numberOmitFrequency = array_fill_keys($dezenasBase, 0.0);
        $omissionSetFrequency = [];
        $pairKeepFrequency = [];
        $totalWeight = 0.0;

        foreach ($source as $index => $candidate) {
            $game = $this->normalizeNumbers($candidate['dezenas'] ?? []);
            $omitted = $this->normalizeNumbers($candidate['omitted_dezenas'] ?? []);
            $weight = 1.0 + ((float) ($candidate['normalized_score'] ?? 0.0) * 3.0) + (($sourceSize - $index) / max(1, $sourceSize));

            $totalWeight += $weight;

            foreach ($game as $number) {
                $numberKeepFrequency[$number] = ($numberKeepFrequency[$number] ?? 0.0) + $weight;
            }

            foreach ($omitted as $number) {
                $numberOmitFrequency[$number] = ($numberOmitFrequency[$number] ?? 0.0) + $weight;
            }

            $omittedKey = $this->candidateKey($omitted);
            $omissionSetFrequency[$omittedKey] = ($omissionSetFrequency[$omittedKey] ?? 0.0) + $weight;

            for ($i = 0; $i < count($game); $i++) {
                for ($j = $i + 1; $j < count($game); $j++) {
                    $key = $game[$i] . '-' . $game[$j];
                    $pairKeepFrequency[$key] = ($pairKeepFrequency[$key] ?? 0.0) + $weight;
                }
            }
        }

        foreach ($numberKeepFrequency as $number => $value) {
            $numberKeepFrequency[$number] = $totalWeight > 0 ? $value / $totalWeight : 0.0;
        }

        foreach ($numberOmitFrequency as $number => $value) {
            $numberOmitFrequency[$number] = $totalWeight > 0 ? $value / $totalWeight : 0.0;
        }

        $maxOmissionSet = empty($omissionSetFrequency) ? 1.0 : max($omissionSetFrequency);
        $maxPairKeep = empty($pairKeepFrequency) ? 1.0 : max($pairKeepFrequency);

        foreach ($omissionSetFrequency as $key => $value) {
            $omissionSetFrequency[$key] = $maxOmissionSet > 0 ? $value / $maxOmissionSet : 0.0;
        }

        foreach ($pairKeepFrequency as $key => $value) {
            $pairKeepFrequency[$key] = $maxPairKeep > 0 ? $value / $maxPairKeep : 0.0;
        }

        $rankedKeepNumbers = [];
        $rankedOmitNumbers = [];

        foreach ($dezenasBase as $number) {
            $profile = $numberProfiles[$number] ?? [];
            $strength = (float) ($profile['score'] ?? $profile['maturity'] ?? 0.5);
            $maturity = (float) ($profile['maturity'] ?? $strength);
            $affinity = (float) ($profile['affinity'] ?? 0.5);
            $returnPressure = (float) ($profile['return_pressure'] ?? 0.5);
            $persistence = (float) ($profile['persistence'] ?? 0.5);

            $keepScore =
                (($numberKeepFrequency[$number] ?? 0.0) * 0.56) +
                ($strength * 0.16) +
                ($maturity * 0.10) +
                ($affinity * 0.10) +
                ($returnPressure * 0.05) +
                ($persistence * 0.03);

            $omitScore =
                (($numberOmitFrequency[$number] ?? 0.0) * 0.50) +
                ((1.0 - $strength) * 0.18) +
                ((1.0 - $maturity) * 0.10) +
                ((1.0 - $affinity) * 0.10) +
                ((1.0 - $returnPressure) * 0.07) +
                ((1.0 - $persistence) * 0.05);

            $rankedKeepNumbers[] = [
                'number' => $number,
                'score' => $keepScore,
            ];

            $rankedOmitNumbers[] = [
                'number' => $number,
                'score' => $omitScore,
            ];
        }

        usort($rankedKeepNumbers, function (array $a, array $b): int {
            if (($a['score'] ?? 0) === ($b['score'] ?? 0)) {
                return ((int) $a['number']) <=> ((int) $b['number']);
            }

            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });

        usort($rankedOmitNumbers, function (array $a, array $b): int {
            if (($a['score'] ?? 0) === ($b['score'] ?? 0)) {
                return ((int) $a['number']) <=> ((int) $b['number']);
            }

            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });

        $preferredOmitCount = min(
            count($dezenasBase),
            max($quantidadeOmitidas + 2, $quantidadeOmitidas * 3)
        );

        return [
            'keep_frequency' => $numberKeepFrequency,
            'omit_frequency' => $numberOmitFrequency,
            'omission_set_frequency' => $omissionSetFrequency,
            'pair_keep_frequency' => $pairKeepFrequency,
            'protected_keep_numbers' => array_map(fn (array $item) => (int) $item['number'], array_slice($rankedKeepNumbers, 0, 15)),
            'preferred_omit_numbers' => array_map(fn (array $item) => (int) $item['number'], array_slice($rankedOmitNumbers, 0, $preferredOmitCount)),
            'ranked_keep_numbers' => $rankedKeepNumbers,
            'ranked_omit_numbers' => $rankedOmitNumbers,
        ];
    }

    protected function adaptiveOmissionPortfolioValue(
        array $candidate,
        array $omissionModel,
        array $numberProfiles,
        int $quantidadeOmitidas
    ): float {
        $game = $this->normalizeNumbers($candidate['dezenas'] ?? []);
        $omitted = $this->normalizeNumbers($candidate['omitted_dezenas'] ?? []);

        $rawValue = $this->rawValue($candidate);
        $keepValue = $this->keepConvergenceValue($game, $omissionModel);
        $omitValue = $this->omissionSetValue($omitted, $omissionModel, $numberProfiles);
        $protectedPenalty = $this->protectedOmissionPenalty($omitted, $omissionModel, $quantidadeOmitidas);

        return
            ($rawValue * $this->rawWeight($quantidadeOmitidas)) +
            ($keepValue * $this->keepWeight($quantidadeOmitidas)) +
            ($omitValue * $this->omitWeight($quantidadeOmitidas)) -
            $protectedPenalty;
    }

    protected function keepConvergenceValue(array $game, array $omissionModel): float
    {
        $keepFrequency = $omissionModel['keep_frequency'] ?? [];
        $pairKeepFrequency = $omissionModel['pair_keep_frequency'] ?? [];
        $protected = $omissionModel['protected_keep_numbers'] ?? [];

        $value = 0.0;
        $protectedHits = count(array_intersect($game, $protected));

        foreach ($game as $number) {
            $value += (float) ($keepFrequency[$number] ?? 0.0) * 140.0;
        }

        for ($i = 0; $i < count($game); $i++) {
            for ($j = $i + 1; $j < count($game); $j++) {
                $key = $game[$i] . '-' . $game[$j];
                $value += ((float) ($pairKeepFrequency[$key] ?? 0.0)) * 1.2;
            }
        }

        if ($protectedHits >= 15) {
            $value += 4200.0;
        } elseif ($protectedHits === 14) {
            $value += 1800.0;
        } elseif ($protectedHits === 13) {
            $value += 650.0;
        }

        return $value;
    }

    protected function omissionSetValue(array $omitted, array $omissionModel, array $numberProfiles): float
    {
        $omitFrequency = $omissionModel['omit_frequency'] ?? [];
        $omissionSetFrequency = $omissionModel['omission_set_frequency'] ?? [];
        $preferred = $omissionModel['preferred_omit_numbers'] ?? [];

        $value = 0.0;
        $omittedKey = $this->candidateKey($omitted);

        $value += ((float) ($omissionSetFrequency[$omittedKey] ?? 0.0)) * 620.0;
        $value += count(array_intersect($omitted, $preferred)) * 165.0;

        foreach ($omitted as $number) {
            $profile = $numberProfiles[$number] ?? [];

            $strength = (float) ($profile['score'] ?? $profile['maturity'] ?? 0.5);
            $maturity = (float) ($profile['maturity'] ?? $strength);
            $affinity = (float) ($profile['affinity'] ?? 0.5);
            $returnPressure = (float) ($profile['return_pressure'] ?? 0.5);
            $persistence = (float) ($profile['persistence'] ?? 0.5);
            $lastDrawPresence = (float) ($profile['last_draw_presence'] ?? 0.0);

            $value += ((float) ($omitFrequency[$number] ?? 0.0)) * 210.0;
            $value += (1.0 - $strength) * 80.0;
            $value += (1.0 - $maturity) * 40.0;
            $value += (1.0 - $affinity) * 34.0;
            $value += (1.0 - $returnPressure) * 22.0;
            $value += (1.0 - $persistence) * 18.0;

            if ($lastDrawPresence <= 0.0) {
                $value += 12.0;
            } else {
                $value -= 14.0;
            }
        }

        $value += $this->omittedStructuralSpreadBonus($omitted);

        return $value;
    }

    protected function protectedOmissionPenalty(array $omitted, array $omissionModel, int $quantidadeOmitidas): float
    {
        $protected = $omissionModel['protected_keep_numbers'] ?? [];
        $protectedOmitted = count(array_intersect($omitted, $protected));

        if ($protectedOmitted <= 0) {
            return 0.0;
        }

        return $protectedOmitted * match ($quantidadeOmitidas) {
            1 => 1800.0,
            2 => 1400.0,
            3 => 1000.0,
            4 => 760.0,
            5 => 620.0,
            default => 900.0,
        };
    }

    protected function canAcceptAdaptiveCandidate(
        array $candidate,
        array $selected,
        array $omissionModel,
        int $quantidadeJogos,
        int $quantidadeOmitidas
    ): bool {
        $game = $this->normalizeNumbers($candidate['dezenas'] ?? []);
        $omitted = $this->normalizeNumbers($candidate['omitted_dezenas'] ?? []);
        $protected = $omissionModel['protected_keep_numbers'] ?? [];
        $protectedHits = count(array_intersect($game, $protected));
        $protectedOmitted = count(array_intersect($omitted, $protected));

        $minimumProtectedHits = match ($quantidadeOmitidas) {
            1 => 14,
            2 => 14,
            3 => 13,
            4 => 12,
            5 => 12,
            default => 13,
        };

        $maximumProtectedOmitted = match ($quantidadeOmitidas) {
            1 => 1,
            2 => 1,
            3 => 2,
            4 => 3,
            5 => 3,
            default => 2,
        };

        if ($protectedHits < $minimumProtectedHits) {
            return false;
        }

        if ($protectedOmitted > $maximumProtectedOmitted) {
            return false;
        }

        $sameOmissionCount = 0;
        $nearCloneCount = 0;

        foreach ($selected as $selectedGame) {
            $selectedNumbers = $this->normalizeNumbers($selectedGame['dezenas'] ?? []);
            $selectedOmitted = $this->normalizeNumbers($selectedGame['omitted_dezenas'] ?? []);

            if ($this->candidateKey($selectedOmitted) === $this->candidateKey($omitted)) {
                $sameOmissionCount++;
            }

            if (count(array_intersect($game, $selectedNumbers)) >= 14) {
                $nearCloneCount++;
            }
        }

        if ($sameOmissionCount >= $this->sameOmissionLimit($quantidadeOmitidas, $quantidadeJogos)) {
            return false;
        }

        if ($nearCloneCount >= $this->nearCloneLimit($quantidadeOmitidas, $quantidadeJogos)) {
            return false;
        }

        return true;
    }

    protected function rawValue(array $candidate): float
    {
        $score = (float) ($candidate['normalized_score'] ?? 0.0);
        $baseScore = (float) ($candidate['base_score'] ?? 0.0);
        $eliteBonus = (float) ($candidate['elite_bonus'] ?? 0.0);
        $survivalQuality = (float) ($candidate['survival_quality'] ?? 0.0);
        $correlationQuality = (float) ($candidate['correlation_quality'] ?? 0.0);
        $structureQuality = (float) ($candidate['structure_quality'] ?? 0.0);

        $value = 0.0;

        $value += $score * 620.0;
        $value += $baseScore * 1.45;
        $value += min(160.0, $eliteBonus * 6.0);
        $value += $survivalQuality * 95.0;
        $value += $correlationQuality * 42.0;
        $value += $structureQuality * 30.0;

        if ($score >= 0.99) {
            $value += 2400.0;
        } elseif ($score >= 0.97) {
            $value += 1650.0;
        } elseif ($score >= 0.94) {
            $value += 920.0;
        } elseif ($score >= 0.90) {
            $value += 520.0;
        } elseif ($score >= 0.84) {
            $value += 220.0;
        }

        return $value;
    }

    protected function addCandidate(
        array &$selected,
        array &$seen,
        array &$coverage,
        array $candidate
    ): bool {
        $key = $this->candidateKey($candidate['dezenas'] ?? []);

        if (isset($seen[$key])) {
            return false;
        }

        $selected[] = $candidate;
        $seen[$key] = true;

        $omitted = $this->normalizeNumbers($candidate['omitted_dezenas'] ?? []);

        foreach ($omitted as $number) {
            $coverage['singles'][$number] = true;
            $coverage['frequency'][$number] = ($coverage['frequency'][$number] ?? 0) + 1;
        }

        return true;
    }

    protected function omittedStructuralSpreadBonus(array $omitted): float
    {
        if (count($omitted) <= 1) {
            return 0.0;
        }

        $lines = [];
        $zones = [];

        foreach ($omitted as $number) {
            $line = $this->line((int) $number);
            $zone = $this->zone((int) $number);

            $lines[$line] = true;
            $zones[$zone] = true;
        }

        return (count($lines) * 8.0) + (count($zones) * 6.0);
    }

    protected function normalizeNumberProfiles(array $numberScores): array
    {
        $profiles = [];

        foreach (range(1, 25) as $number) {
            $raw = $numberScores[$number] ?? [];

            if (is_numeric($raw)) {
                $profiles[$number] = [
                    'number' => $number,
                    'score' => (float) $raw,
                    'maturity' => (float) $raw,
                    'affinity' => 0.5,
                    'return_pressure' => 0.5,
                    'persistence' => 0.5,
                    'frequency' => (float) $raw,
                    'cycle' => 0.5,
                    'last_draw_presence' => 0.0,
                ];

                continue;
            }

            if (is_array($raw)) {
                $profiles[$number] = $raw + [
                    'number' => $number,
                    'score' => 0.5,
                    'maturity' => 0.5,
                    'affinity' => 0.5,
                    'return_pressure' => 0.5,
                    'persistence' => 0.5,
                    'frequency' => 0.5,
                    'cycle' => 0.5,
                    'last_draw_presence' => 0.0,
                ];

                continue;
            }

            $profiles[$number] = [
                'number' => $number,
                'score' => 0.5,
                'maturity' => 0.5,
                'affinity' => 0.5,
                'return_pressure' => 0.5,
                'persistence' => 0.5,
                'frequency' => 0.5,
                'cycle' => 0.5,
                'last_draw_presence' => 0.0,
            ];
        }

        return $profiles;
    }

    protected function emptyCoverageState(array $dezenasBase): array
    {
        return [
            'singles' => [],
            'pairs' => [],
            'triples' => [],
            'frequency' => array_fill_keys($dezenasBase, 0),
        ];
    }

    protected function windowMultiplier(int $quantidadeOmitidas): int
    {
        return match ($quantidadeOmitidas) {
            1 => 6,
            2 => 12,
            3 => 24,
            4 => 36,
            5 => 48,
            default => 24,
        };
    }

    protected function minimumWindowSize(int $quantidadeOmitidas): int
    {
        return match ($quantidadeOmitidas) {
            1 => 24,
            2 => 80,
            3 => 420,
            4 => 720,
            5 => 1050,
            default => 420,
        };
    }

    protected function minimumEliteLock(int $quantidadeOmitidas): int
    {
        return match ($quantidadeOmitidas) {
            1 => 3,
            2 => 5,
            3 => 8,
            4 => 10,
            5 => 12,
            default => 8,
        };
    }

    protected function eliteLockRatio(int $quantidadeOmitidas): float
    {
        return match ($quantidadeOmitidas) {
            1 => 0.20,
            2 => 0.24,
            3 => 0.30,
            4 => 0.34,
            5 => 0.38,
            default => 0.30,
        };
    }

    protected function rawWeight(int $quantidadeOmitidas): float
    {
        return match ($quantidadeOmitidas) {
            1 => 0.48,
            2 => 0.46,
            3 => 0.40,
            4 => 0.36,
            5 => 0.34,
            default => 0.40,
        };
    }

    protected function keepWeight(int $quantidadeOmitidas): float
    {
        return match ($quantidadeOmitidas) {
            1 => 0.28,
            2 => 0.30,
            3 => 0.32,
            4 => 0.34,
            5 => 0.35,
            default => 0.32,
        };
    }

    protected function omitWeight(int $quantidadeOmitidas): float
    {
        return match ($quantidadeOmitidas) {
            1 => 0.24,
            2 => 0.24,
            3 => 0.28,
            4 => 0.30,
            5 => 0.31,
            default => 0.28,
        };
    }

    protected function sameOmissionLimit(int $quantidadeOmitidas, int $quantidadeJogos): int
    {
        return match ($quantidadeOmitidas) {
            1 => 1,
            2 => 1,
            3 => max(2, (int) floor($quantidadeJogos * 0.08)),
            4 => max(2, (int) floor($quantidadeJogos * 0.10)),
            5 => max(3, (int) floor($quantidadeJogos * 0.12)),
            default => 2,
        };
    }

    protected function nearCloneLimit(int $quantidadeOmitidas, int $quantidadeJogos): int
    {
        return match ($quantidadeOmitidas) {
            1 => max(2, (int) floor($quantidadeJogos * 0.25)),
            2 => max(3, (int) floor($quantidadeJogos * 0.30)),
            3 => max(5, (int) floor($quantidadeJogos * 0.36)),
            4 => max(6, (int) floor($quantidadeJogos * 0.40)),
            5 => max(8, (int) floor($quantidadeJogos * 0.45)),
            default => max(5, (int) floor($quantidadeJogos * 0.36)),
        };
    }

    protected function normalizeNumbers(array $numbers): array
    {
        $numbers = array_values(array_unique(array_map('intval', $numbers)));
        sort($numbers);

        return $numbers;
    }

    protected function candidateKey(array $dezenas): string
    {
        return implode('-', $this->normalizeNumbers($dezenas));
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
