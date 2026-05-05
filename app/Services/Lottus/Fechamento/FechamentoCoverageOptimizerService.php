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

        $numberProfiles = $this->normalizeNumberProfiles($numberScores);

        $workingPool = $this->workingPool(
            pool: $pool,
            quantidadeJogos: $quantidadeJogos,
            quantidadeOmitidas: $quantidadeOmitidas
        );

        $omissionModel = $this->buildAdaptiveOmissionModel(
            workingPool: $workingPool,
            dezenasBase: $dezenasBase,
            quantidadeOmitidas: $quantidadeOmitidas,
            numberProfiles: $numberProfiles
        );

        $ranked = [];

        foreach ($pool as $candidate) {
            $candidate['_adaptive_omission_score'] = $this->adaptiveOmissionPortfolioValue(
                candidate: $candidate,
                omissionModel: $omissionModel,
                numberProfiles: $numberProfiles,
                quantidadeOmitidas: $quantidadeOmitidas
            );

            $candidate['_rank_protection_score'] = $this->rankProtectionScore($candidate, $quantidadeOmitidas);
            $candidate['_is_rank_protected'] = $this->isRankProtectedCandidate($candidate, $quantidadeOmitidas);

            $ranked[] = $candidate;
        }

        usort($ranked, function (array $a, array $b): int {
            $aProtected = ! empty($a['_is_rank_protected']);
            $bProtected = ! empty($b['_is_rank_protected']);

            if ($aProtected !== $bProtected) {
                return $aProtected ? -1 : 1;
            }

            if (($a['_rank_protection_score'] ?? 0.0) !== ($b['_rank_protection_score'] ?? 0.0)) {
                return ($b['_rank_protection_score'] ?? 0.0) <=> ($a['_rank_protection_score'] ?? 0.0);
            }

            if (($a['score'] ?? 0.0) !== ($b['score'] ?? 0.0)) {
                return ($b['score'] ?? 0.0) <=> ($a['score'] ?? 0.0);
            }

            return ($b['normalized_score'] ?? 0.0) <=> ($a['normalized_score'] ?? 0.0);
        });

        $selected = $this->selectRankProtectedCore(
            ranked: $ranked,
            quantidadeJogos: $quantidadeJogos,
            quantidadeOmitidas: $quantidadeOmitidas
        );

        $selected = $this->selectProtectedRawCore(
            ranked: $ranked,
            selected: $selected,
            quantidadeJogos: $quantidadeJogos,
            quantidadeOmitidas: $quantidadeOmitidas
        );

        $selected = $this->selectWithDiversity(
            ranked: $ranked,
            selected: $selected,
            quantidadeJogos: $quantidadeJogos,
            quantidadeOmitidas: $quantidadeOmitidas,
            omissionModel: $omissionModel
        );

        if (count($selected) < $quantidadeJogos) {
            $selected = $this->selectWithRelaxedDiversity(
                ranked: $ranked,
                selected: $selected,
                quantidadeJogos: $quantidadeJogos,
                quantidadeOmitidas: $quantidadeOmitidas
            );
        }

        if (count($selected) < $quantidadeJogos) {
            return [];
        }

        foreach ($selected as $index => &$candidate) {
            $candidate['portfolio_order'] = $index + 1;
            $candidate['coverage_optimized'] = true;
            $candidate['adaptive_omission_optimized'] = true;
            $candidate['diversity_optimized'] = true;
            $candidate['raw_preservation_optimized'] = true;
            $candidate['rank_protection_optimized'] = true;
            $candidate['quantidade_omitidas'] = $quantidadeOmitidas;

            unset($candidate['_adaptive_omission_score']);
            unset($candidate['_portfolio_choice_score']);
            unset($candidate['_raw_protected_order']);
            unset($candidate['_rank_protection_score']);
            unset($candidate['_is_rank_protected']);
        }

        unset($candidate);

        usort($selected, function (array $a, array $b): int {
            $aProtected = ! empty($a['rank_protected']) || ! empty($a['raw_survivor_priority']) || ! empty($a['raw_elite_protected']);
            $bProtected = ! empty($b['rank_protected']) || ! empty($b['raw_survivor_priority']) || ! empty($b['raw_elite_protected']);

            if ($aProtected !== $bProtected) {
                return $aProtected ? -1 : 1;
            }

            $rankComparison = ((int) ($a['raw_rank'] ?? PHP_INT_MAX)) <=> ((int) ($b['raw_rank'] ?? PHP_INT_MAX));

            if ($rankComparison !== 0) {
                return $rankComparison;
            }

            $scoreComparison = ($b['score'] ?? 0) <=> ($a['score'] ?? 0);

            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            return ($b['normalized_score'] ?? 0) <=> ($a['normalized_score'] ?? 0);
        });

        return array_slice($selected, 0, $quantidadeJogos);
    }

    protected function workingPool(array $pool, int $quantidadeJogos, int $quantidadeOmitidas): array
    {
        $windowSize = min(
            count($pool),
            max($quantidadeJogos * $this->windowMultiplier($quantidadeOmitidas), $this->minimumWindowSize($quantidadeOmitidas))
        );

        if ($quantidadeOmitidas >= 4) {
            return $pool;
        }

        return array_slice($pool, 0, $windowSize);
    }

    protected function attachRawRank(array $pool): array
    {
        foreach ($pool as $index => &$candidate) {
            $candidate['raw_rank'] = $index + 1;
        }

        unset($candidate);

        return $pool;
    }

    protected function selectRankProtectedCore(
        array $ranked,
        int $quantidadeJogos,
        int $quantidadeOmitidas
    ): array {
        $selected = [];
        $seen = [];
        $limit = $this->rankProtectedCoreCount($quantidadeJogos, $quantidadeOmitidas);

        $protected = array_values(array_filter(
            $ranked,
            fn (array $candidate): bool => ! empty($candidate['_is_rank_protected'])
        ));

        usort($protected, function (array $a, array $b): int {
            $rankComparison = ((int) ($a['raw_rank'] ?? PHP_INT_MAX)) <=> ((int) ($b['raw_rank'] ?? PHP_INT_MAX));

            if ($rankComparison !== 0) {
                return $rankComparison;
            }

            if (($a['_rank_protection_score'] ?? 0.0) !== ($b['_rank_protection_score'] ?? 0.0)) {
                return ($b['_rank_protection_score'] ?? 0.0) <=> ($a['_rank_protection_score'] ?? 0.0);
            }

            return ($b['score'] ?? 0.0) <=> ($a['score'] ?? 0.0);
        });

        foreach ($protected as $candidate) {
            if (count($selected) >= $limit) {
                break;
            }

            $key = $this->candidateKey($candidate['dezenas'] ?? []);

            if (isset($seen[$key])) {
                continue;
            }

            if (! $this->respectsRankProtectedDistance($candidate, $selected, $quantidadeOmitidas)) {
                continue;
            }

            $candidate['rank_protected'] = true;
            $candidate['raw_survivor_priority'] = true;

            $selected[] = $candidate;
            $seen[$key] = true;
        }

        return $selected;
    }

    protected function selectProtectedRawCore(
        array $ranked,
        array $selected,
        int $quantidadeJogos,
        int $quantidadeOmitidas
    ): array {
        $seen = [];

        foreach ($selected as $candidate) {
            $seen[$this->candidateKey($candidate['dezenas'] ?? [])] = true;
        }

        $rawSorted = $ranked;

        usort($rawSorted, function (array $a, array $b): int {
            $rankComparison = ((int) ($a['raw_rank'] ?? PHP_INT_MAX)) <=> ((int) ($b['raw_rank'] ?? PHP_INT_MAX));

            if ($rankComparison !== 0) {
                return $rankComparison;
            }

            $scoreComparison = ($b['score'] ?? 0) <=> ($a['score'] ?? 0);

            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            return ($b['normalized_score'] ?? 0) <=> ($a['normalized_score'] ?? 0);
        });

        $protectedCount = $this->protectedRawCoreCount($quantidadeJogos, $quantidadeOmitidas);
        $maxProtectedIntersection = $this->protectedRawMaxIntersection($quantidadeOmitidas);

        foreach ($rawSorted as $candidate) {
            if (count($selected) >= $protectedCount) {
                break;
            }

            $key = $this->candidateKey($candidate['dezenas'] ?? []);

            if (isset($seen[$key])) {
                continue;
            }

            if (! $this->isEliteRawCandidate($candidate) && ! $this->isRankProtectedCandidate($candidate, $quantidadeOmitidas) && count($selected) >= max(1, (int) floor($protectedCount * 0.55))) {
                continue;
            }

            if (! $this->respectsProtectedRawDistance(
                candidate: $candidate,
                selected: $selected,
                maxIntersection: $maxProtectedIntersection,
                quantidadeOmitidas: $quantidadeOmitidas
            )) {
                continue;
            }

            $candidate['raw_elite_protected'] = true;
            $candidate['raw_survivor_priority'] = true;
            $candidate['_raw_protected_order'] = count($selected) + 1;

            $selected[] = $candidate;
            $seen[$key] = true;
        }

        if (empty($selected) && ! empty($rawSorted)) {
            $candidate = $rawSorted[0];
            $candidate['raw_elite_protected'] = true;
            $candidate['raw_survivor_priority'] = true;
            $candidate['_raw_protected_order'] = 1;
            $selected[] = $candidate;
        }

        return $selected;
    }

    protected function selectWithDiversity(
        array $ranked,
        array $selected,
        int $quantidadeJogos,
        int $quantidadeOmitidas,
        array $omissionModel
    ): array {
        $seen = [];
        $coverage = [];

        foreach ($selected as $candidate) {
            $seen[$this->candidateKey($candidate['dezenas'] ?? [])] = true;

            foreach ($this->normalizeNumbers($candidate['omitted_dezenas'] ?? []) as $number) {
                $coverage[$number] = ($coverage[$number] ?? 0) + 1;
            }
        }

        $strictMaxIntersection = $this->strictMaxIntersection($quantidadeOmitidas);
        $commercialMaxIntersection = $this->commercialMaxIntersection($quantidadeOmitidas);

        foreach ($ranked as $candidate) {
            if (count($selected) >= $quantidadeJogos) {
                break;
            }

            $key = $this->candidateKey($candidate['dezenas'] ?? []);

            if (isset($seen[$key])) {
                continue;
            }

            if (! $this->canAcceptAdaptiveCandidate(
                candidate: $candidate,
                selected: $selected,
                omissionModel: $omissionModel,
                quantidadeJogos: $quantidadeJogos,
                quantidadeOmitidas: $quantidadeOmitidas
            )) {
                continue;
            }

            if (! $this->respectsInternalDistance(
                candidate: $candidate,
                selected: $selected,
                maxIntersection: $strictMaxIntersection,
                quantidadeOmitidas: $quantidadeOmitidas,
                quantidadeJogos: $quantidadeJogos
            )) {
                continue;
            }

            $candidate['_portfolio_choice_score'] = $this->portfolioChoiceScore(
                candidate: $candidate,
                selected: $selected,
                coverage: $coverage,
                quantidadeOmitidas: $quantidadeOmitidas
            );

            $this->addCandidate(
                selected: $selected,
                seen: $seen,
                coverage: $coverage,
                candidate: $candidate
            );
        }

        if (count($selected) >= $quantidadeJogos) {
            return $selected;
        }

        foreach ($ranked as $candidate) {
            if (count($selected) >= $quantidadeJogos) {
                break;
            }

            $key = $this->candidateKey($candidate['dezenas'] ?? []);

            if (isset($seen[$key])) {
                continue;
            }

            if (! $this->respectsInternalDistance(
                candidate: $candidate,
                selected: $selected,
                maxIntersection: $commercialMaxIntersection,
                quantidadeOmitidas: $quantidadeOmitidas,
                quantidadeJogos: $quantidadeJogos
            )) {
                continue;
            }

            $candidate['_portfolio_choice_score'] = $this->portfolioChoiceScore(
                candidate: $candidate,
                selected: $selected,
                coverage: $coverage,
                quantidadeOmitidas: $quantidadeOmitidas
            );

            $this->addCandidate(
                selected: $selected,
                seen: $seen,
                coverage: $coverage,
                candidate: $candidate
            );
        }

        return $selected;
    }

    protected function selectWithRelaxedDiversity(
        array $ranked,
        array $selected,
        int $quantidadeJogos,
        int $quantidadeOmitidas
    ): array {
        $seen = [];
        $coverage = [];

        foreach ($selected as $candidate) {
            $seen[$this->candidateKey($candidate['dezenas'] ?? [])] = true;

            foreach ($this->normalizeNumbers($candidate['omitted_dezenas'] ?? []) as $number) {
                $coverage[$number] = ($coverage[$number] ?? 0) + 1;
            }
        }

        foreach ($ranked as $candidate) {
            if (count($selected) >= $quantidadeJogos) {
                break;
            }

            $key = $this->candidateKey($candidate['dezenas'] ?? []);

            if (isset($seen[$key])) {
                continue;
            }

            if (! $this->respectsEmergencyDistance(
                candidate: $candidate,
                selected: $selected,
                quantidadeOmitidas: $quantidadeOmitidas,
                quantidadeJogos: $quantidadeJogos
            )) {
                continue;
            }

            $candidate['_portfolio_choice_score'] = $this->portfolioChoiceScore(
                candidate: $candidate,
                selected: $selected,
                coverage: $coverage,
                quantidadeOmitidas: $quantidadeOmitidas
            );

            $this->addCandidate(
                selected: $selected,
                seen: $seen,
                coverage: $coverage,
                candidate: $candidate
            );
        }

        foreach ($ranked as $candidate) {
            if (count($selected) >= $quantidadeJogos) {
                break;
            }

            $key = $this->candidateKey($candidate['dezenas'] ?? []);

            if (isset($seen[$key])) {
                continue;
            }

            $this->addCandidate(
                selected: $selected,
                seen: $seen,
                coverage: $coverage,
                candidate: $candidate
            );
        }

        return $selected;
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
                (($numberKeepFrequency[$number] ?? 0.0) * 0.34) +
                ($strength * 0.24) +
                ($maturity * 0.16) +
                ($affinity * 0.14) +
                ($persistence * 0.12);

            $omitScore =
                (($numberOmitFrequency[$number] ?? 0.0) * 0.34) +
                ((1.0 - $strength) * 0.18) +
                ((1.0 - $maturity) * 0.16) +
                ($returnPressure * 0.20) +
                ((1.0 - $persistence) * 0.12);

            $rankedKeepNumbers[$number] = $keepScore;
            $rankedOmitNumbers[$number] = $omitScore;
        }

        arsort($rankedKeepNumbers);
        arsort($rankedOmitNumbers);

        return [
            'number_keep_frequency' => $numberKeepFrequency,
            'number_omit_frequency' => $numberOmitFrequency,
            'omission_set_frequency' => $omissionSetFrequency,
            'pair_keep_frequency' => $pairKeepFrequency,
            'protected_keep_numbers' => array_slice(array_keys($rankedKeepNumbers), 0, min(14, count($rankedKeepNumbers))),
            'preferred_omit_numbers' => array_slice(array_keys($rankedOmitNumbers), 0, max(4, $quantidadeOmitidas + 2)),
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
        $omittedKey = $this->candidateKey($omitted);

        $raw = $this->rawValue($candidate);
        $keepValue = 0.0;
        $omitValue = 0.0;
        $pairValue = 0.0;

        foreach ($game as $number) {
            $profile = $numberProfiles[$number] ?? [];

            $keepValue +=
                (($omissionModel['number_keep_frequency'][$number] ?? 0.0) * 130.0) +
                ((float) ($profile['score'] ?? 0.5) * 50.0) +
                ((float) ($profile['maturity'] ?? 0.5) * 36.0) +
                ((float) ($profile['affinity'] ?? 0.5) * 28.0);
        }

        foreach ($omitted as $number) {
            $profile = $numberProfiles[$number] ?? [];

            $omitValue +=
                (($omissionModel['number_omit_frequency'][$number] ?? 0.0) * 140.0) +
                ((1.0 - (float) ($profile['score'] ?? 0.5)) * 34.0) +
                ((float) ($profile['return_pressure'] ?? 0.5) * 24.0);
        }

        for ($i = 0; $i < count($game); $i++) {
            for ($j = $i + 1; $j < count($game); $j++) {
                $key = $game[$i] . '-' . $game[$j];
                $pairValue += (float) ($omissionModel['pair_keep_frequency'][$key] ?? 0.0);
            }
        }

        $pairValue = $pairValue / max(1, 105);
        $omissionSetPenalty = (float) ($omissionModel['omission_set_frequency'][$omittedKey] ?? 0.0) * 120.0;
        $protectedPenalty = $this->protectedOmissionPenalty($omitted, $omissionModel, $quantidadeOmitidas);
        $spreadBonus = $this->omittedStructuralSpreadBonus($omitted);

        return
            ($raw * $this->rawWeight($quantidadeOmitidas)) +
            ($keepValue * $this->keepWeight($quantidadeOmitidas)) +
            ($omitValue * $this->omitWeight($quantidadeOmitidas)) +
            ($pairValue * 120.0) +
            $spreadBonus -
            $omissionSetPenalty -
            $protectedPenalty;
    }

    protected function portfolioChoiceScore(
        array $candidate,
        array $selected,
        array $coverage,
        int $quantidadeOmitidas
    ): float {
        $game = $this->normalizeNumbers($candidate['dezenas'] ?? []);
        $omitted = $this->normalizeNumbers($candidate['omitted_dezenas'] ?? []);

        $base = (float) ($candidate['_adaptive_omission_score'] ?? 0.0);
        $newOmittedCoverage = 0.0;
        $clonePenalty = 0.0;

        foreach ($omitted as $number) {
            if (! isset($coverage[$number])) {
                $newOmittedCoverage += 120.0;
            } else {
                $newOmittedCoverage += max(0.0, 40.0 - ($coverage[$number] * 8.0));
            }
        }

        foreach ($selected as $selectedGame) {
            $selectedNumbers = $this->normalizeNumbers($selectedGame['dezenas'] ?? []);
            $intersection = count(array_intersect($game, $selectedNumbers));
            $hammingDistance = 15 - $intersection;

            if ($intersection >= 14 && ! $this->isRankProtectedCandidate($candidate, $quantidadeOmitidas)) {
                $clonePenalty += 900.0;
            } elseif ($intersection === 13 && ! $this->isRankProtectedCandidate($candidate, $quantidadeOmitidas)) {
                $clonePenalty += 260.0;
            } elseif ($intersection === 12) {
                $clonePenalty += 80.0;
            }

            $base += $hammingDistance * 16.0;
        }

        return $base + $newOmittedCoverage - $clonePenalty;
    }

    protected function canAcceptAdaptiveCandidate(
        array $candidate,
        array $selected,
        array $omissionModel,
        int $quantidadeJogos,
        int $quantidadeOmitidas
    ): bool {
        if ($this->isRankProtectedCandidate($candidate, $quantidadeOmitidas) || $this->isEliteRawCandidate($candidate)) {
            return true;
        }

        $game = $this->normalizeNumbers($candidate['dezenas'] ?? []);
        $omitted = $this->normalizeNumbers($candidate['omitted_dezenas'] ?? []);
        $protected = $omissionModel['protected_keep_numbers'] ?? [];
        $protectedHits = count(array_intersect($game, $protected));
        $protectedOmitted = count(array_intersect($omitted, $protected));

        $minimumProtectedHits = match ($quantidadeOmitidas) {
            1 => 14,
            2 => 13,
            3 => 12,
            4 => 11,
            5 => 10,
            default => 12,
        };

        $maximumProtectedOmitted = match ($quantidadeOmitidas) {
            1 => 1,
            2 => 2,
            3 => 3,
            4 => 4,
            5 => 5,
            default => 3,
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

    protected function respectsInternalDistance(
        array $candidate,
        array $selected,
        int $maxIntersection,
        int $quantidadeOmitidas,
        int $quantidadeJogos
    ): bool {
        if ($this->isRankProtectedCandidate($candidate, $quantidadeOmitidas) || $this->isEliteRawCandidate($candidate)) {
            return true;
        }

        if (empty($selected)) {
            return true;
        }

        $game = $this->normalizeNumbers($candidate['dezenas'] ?? []);
        $allowedHighSimilarity = $this->allowedHighSimilarityCount($quantidadeOmitidas, $quantidadeJogos);
        $highSimilarityCount = 0;

        foreach ($selected as $selectedGame) {
            $selectedNumbers = $this->normalizeNumbers($selectedGame['dezenas'] ?? []);
            $intersection = count(array_intersect($game, $selectedNumbers));

            if ($intersection > $maxIntersection) {
                $highSimilarityCount++;
            }

            if ($intersection >= 14) {
                return false;
            }
        }

        return $highSimilarityCount <= $allowedHighSimilarity;
    }

    protected function respectsEmergencyDistance(
        array $candidate,
        array $selected,
        int $quantidadeOmitidas,
        int $quantidadeJogos
    ): bool {
        if (empty($selected)) {
            return true;
        }

        if ($this->isRankProtectedCandidate($candidate, $quantidadeOmitidas) || $this->isEliteRawCandidate($candidate)) {
            return true;
        }

        $game = $this->normalizeNumbers($candidate['dezenas'] ?? []);
        $nearCloneCount = 0;

        foreach ($selected as $selectedGame) {
            $selectedNumbers = $this->normalizeNumbers($selectedGame['dezenas'] ?? []);
            $intersection = count(array_intersect($game, $selectedNumbers));

            if ($intersection >= 14) {
                $nearCloneCount++;
            }
        }

        return $nearCloneCount < max(1, (int) floor($quantidadeJogos * 0.08));
    }

    protected function respectsRankProtectedDistance(array $candidate, array $selected, int $quantidadeOmitidas): bool
    {
        if (empty($selected)) {
            return true;
        }

        $game = $this->normalizeNumbers($candidate['dezenas'] ?? []);
        $nearCloneCount = 0;

        foreach ($selected as $selectedGame) {
            $selectedNumbers = $this->normalizeNumbers($selectedGame['dezenas'] ?? []);
            $intersection = count(array_intersect($game, $selectedNumbers));

            if ($intersection === 15) {
                return false;
            }

            if ($intersection >= 14) {
                $nearCloneCount++;
            }
        }

        return $nearCloneCount <= match ($quantidadeOmitidas) {
            1, 2 => 1,
            3 => 2,
            4 => 4,
            5 => 5,
            default => 2,
        };
    }

    protected function rawValue(array $candidate): float
    {
        $score = (float) ($candidate['normalized_score'] ?? 0.0);
        $baseScore = (float) ($candidate['base_score'] ?? 0.0);
        $eliteBonus = (float) ($candidate['elite_bonus'] ?? 0.0);
        $survivalQuality = (float) ($candidate['survival_quality'] ?? 0.0);
        $explosionQuality = (float) ($candidate['explosion_quality'] ?? 0.0);
        $correlationQuality = (float) ($candidate['correlation_quality'] ?? 0.0);
        $structureQuality = (float) ($candidate['structure_quality'] ?? 0.0);
        $rawRank = (int) ($candidate['raw_rank'] ?? PHP_INT_MAX);

        $value = 0.0;

        $value += $score * 520.0;
        $value += $baseScore * 1.15;
        $value += min(140.0, $eliteBonus * 4.0);
        $value += $survivalQuality * 55.0;
        $value += $explosionQuality * 95.0;
        $value += $correlationQuality * 52.0;
        $value += $structureQuality * 20.0;

        if ($rawRank <= 100) {
            $value += 1200.0;
        } elseif ($rawRank <= 300) {
            $value += 900.0;
        } elseif ($rawRank <= 600) {
            $value += 650.0;
        } elseif ($rawRank <= 900) {
            $value += 420.0;
        }

        if ($this->isRankProtectedCandidate($candidate, 4)) {
            $value += 1000.0;
        }

        if ($score >= 0.99) {
            $value += 1800.0;
        } elseif ($score >= 0.97) {
            $value += 1200.0;
        } elseif ($score >= 0.94) {
            $value += 720.0;
        } elseif ($score >= 0.90) {
            $value += 360.0;
        } elseif ($score >= 0.84) {
            $value += 160.0;
        }

        return $value;
    }

    protected function rankProtectionScore(array $candidate, int $quantidadeOmitidas): float
    {
        $rawRank = (int) ($candidate['raw_rank'] ?? PHP_INT_MAX);
        $score = (float) ($candidate['score'] ?? 0.0);
        $normalizedScore = (float) ($candidate['normalized_score'] ?? 0.0);
        $correlationQuality = (float) ($candidate['correlation_quality'] ?? 0.0);
        $survivalQuality = (float) ($candidate['survival_quality'] ?? 0.0);
        $explosionQuality = (float) ($candidate['explosion_quality'] ?? 0.0);

        $rankScore = max(0.0, ($this->rankProtectionWindow($quantidadeOmitidas) - $rawRank + 1) / max(1, $this->rankProtectionWindow($quantidadeOmitidas)));

        return
            ($rankScore * 1000.0) +
            ($normalizedScore * 220.0) +
            ($score * 2.0) +
            ($correlationQuality * 120.0) +
            ($survivalQuality * 60.0) +
            ($explosionQuality * 80.0);
    }

    protected function isRankProtectedCandidate(array $candidate, int $quantidadeOmitidas): bool
    {
        $rawRank = (int) ($candidate['raw_rank'] ?? PHP_INT_MAX);

        return $rawRank <= $this->rankProtectionWindow($quantidadeOmitidas);
    }

    protected function rankProtectionWindow(int $quantidadeOmitidas): int
    {
        return match ($quantidadeOmitidas) {
            1 => 80,
            2 => 180,
            3 => 420,
            4 => 900,
            5 => 1400,
            default => 420,
        };
    }

    protected function rankProtectedCoreCount(int $quantidadeJogos, int $quantidadeOmitidas): int
    {
        return match ($quantidadeOmitidas) {
            1 => max(3, (int) floor($quantidadeJogos * 0.10)),
            2 => max(6, (int) floor($quantidadeJogos * 0.14)),
            3 => max(10, (int) floor($quantidadeJogos * 0.18)),
            4 => max(22, (int) floor($quantidadeJogos * 0.38)),
            5 => max(32, (int) floor($quantidadeJogos * 0.44)),
            default => max(10, (int) floor($quantidadeJogos * 0.18)),
        };
    }

    protected function isEliteRawCandidate(array $candidate): bool
    {
        $normalizedScore = (float) ($candidate['normalized_score'] ?? 0.0);
        $score = (float) ($candidate['score'] ?? 0.0);
        $originalScore = (float) ($candidate['original_score'] ?? $score);

        if (! empty($candidate['rank_protected'])) {
            return true;
        }

        if (! empty($candidate['raw_survivor_priority'])) {
            return true;
        }

        if ($normalizedScore >= 0.97) {
            return true;
        }

        if ($normalizedScore >= 0.94 && ($candidate['elite_bonus'] ?? 0) > 0) {
            return true;
        }

        if ($originalScore > 0 && $score > 0 && abs($originalScore - $score) <= 0.000001 && $normalizedScore >= 0.95) {
            return true;
        }

        return false;
    }

    protected function protectedRawCoreCount(int $quantidadeJogos, int $quantidadeOmitidas): int
    {
        return match ($quantidadeOmitidas) {
            1 => max(4, (int) floor($quantidadeJogos * 0.34)),
            2 => max(6, (int) floor($quantidadeJogos * 0.30)),
            3 => max(9, (int) floor($quantidadeJogos * 0.26)),
            4 => max(28, (int) floor($quantidadeJogos * 0.46)),
            5 => max(38, (int) floor($quantidadeJogos * 0.52)),
            default => max(8, (int) floor($quantidadeJogos * 0.25)),
        };
    }

    protected function protectedRawMaxIntersection(int $quantidadeOmitidas): int
    {
        return match ($quantidadeOmitidas) {
            1 => 14,
            2 => 14,
            3 => 13,
            4 => 14,
            5 => 14,
            default => 13,
        };
    }

    protected function respectsProtectedRawDistance(
        array $candidate,
        array $selected,
        int $maxIntersection,
        int $quantidadeOmitidas
    ): bool {
        if ($this->isRankProtectedCandidate($candidate, $quantidadeOmitidas) || $this->isEliteRawCandidate($candidate)) {
            return true;
        }

        if (empty($selected)) {
            return true;
        }

        $game = $this->normalizeNumbers($candidate['dezenas'] ?? []);

        foreach ($selected as $selectedGame) {
            $selectedNumbers = $this->normalizeNumbers($selectedGame['dezenas'] ?? []);
            $intersection = count(array_intersect($game, $selectedNumbers));

            if ($intersection > $maxIntersection) {
                return false;
            }
        }

        return true;
    }

    protected function protectedOmissionPenalty(
        array $omitted,
        array $omissionModel,
        int $quantidadeOmitidas
    ): float {
        $protected = $omissionModel['protected_keep_numbers'] ?? [];
        $protectedOmitted = count(array_intersect($omitted, $protected));

        return $protectedOmitted * match ($quantidadeOmitidas) {
            1 => 1800.0,
            2 => 1100.0,
            3 => 760.0,
            4 => 520.0,
            5 => 380.0,
            default => 760.0,
        };
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
            $coverage[$number] = ($coverage[$number] ?? 0) + 1;
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

    protected function windowMultiplier(int $quantidadeOmitidas): int
    {
        return match ($quantidadeOmitidas) {
            1 => 8,
            2 => 16,
            3 => 28,
            4 => 40,
            5 => 54,
            default => 28,
        };
    }

    protected function minimumWindowSize(int $quantidadeOmitidas): int
    {
        return match ($quantidadeOmitidas) {
            1 => 24,
            2 => 100,
            3 => 460,
            4 => 820,
            5 => 1200,
            default => 460,
        };
    }

    protected function strictMaxIntersection(int $quantidadeOmitidas): int
    {
        return match ($quantidadeOmitidas) {
            1 => 13,
            2 => 13,
            3 => 12,
            4 => 12,
            5 => 12,
            default => 12,
        };
    }

    protected function commercialMaxIntersection(int $quantidadeOmitidas): int
    {
        return match ($quantidadeOmitidas) {
            1 => 13,
            2 => 13,
            3 => 13,
            4 => 13,
            5 => 13,
            default => 13,
        };
    }

    protected function allowedHighSimilarityCount(int $quantidadeOmitidas, int $quantidadeJogos): int
    {
        return match ($quantidadeOmitidas) {
            1 => 0,
            2 => 1,
            3 => max(1, (int) floor($quantidadeJogos * 0.04)),
            4 => max(4, (int) floor($quantidadeJogos * 0.16)),
            5 => max(6, (int) floor($quantidadeJogos * 0.20)),
            default => 1,
        };
    }

    protected function rawWeight(int $quantidadeOmitidas): float
    {
        return match ($quantidadeOmitidas) {
            1 => 0.62,
            2 => 0.60,
            3 => 0.56,
            4 => 0.62,
            5 => 0.64,
            default => 0.56,
        };
    }

    protected function keepWeight(int $quantidadeOmitidas): float
    {
        return match ($quantidadeOmitidas) {
            1 => 0.20,
            2 => 0.22,
            3 => 0.24,
            4 => 0.20,
            5 => 0.18,
            default => 0.24,
        };
    }

    protected function omitWeight(int $quantidadeOmitidas): float
    {
        return match ($quantidadeOmitidas) {
            1 => 0.18,
            2 => 0.18,
            3 => 0.20,
            4 => 0.18,
            5 => 0.18,
            default => 0.20,
        };
    }

    protected function sameOmissionLimit(int $quantidadeOmitidas, int $quantidadeJogos): int
    {
        return match ($quantidadeOmitidas) {
            1 => 1,
            2 => 1,
            3 => max(1, (int) floor($quantidadeJogos * 0.05)),
            4 => max(4, (int) floor($quantidadeJogos * 0.12)),
            5 => max(6, (int) floor($quantidadeJogos * 0.16)),
            default => 1,
        };
    }

    protected function nearCloneLimit(int $quantidadeOmitidas, int $quantidadeJogos): int
    {
        return match ($quantidadeOmitidas) {
            1 => 0,
            2 => 1,
            3 => max(1, (int) floor($quantidadeJogos * 0.04)),
            4 => max(5, (int) floor($quantidadeJogos * 0.18)),
            5 => max(7, (int) floor($quantidadeJogos * 0.22)),
            default => 1,
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