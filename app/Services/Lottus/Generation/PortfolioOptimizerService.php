<?php

namespace App\Services\Lottus\Generation;

use App\Services\Lottus\Generation\ClusterEliteLockService;
use App\Services\Lottus\Generation\EliteSelectionAuditService;
use App\Services\Lottus\Generation\EliteSurvivalService;

class PortfolioOptimizerService
{
    protected array $tuning;

    public function __construct(?array $tuning = null)
    {
        $this->tuning = $tuning ?? config('lottus_portfolio_tuning.default', []);
    }

    public function optimize(array $rankedGames, int $quantidade): array
{
    if ($quantidade <= 0 || empty($rankedGames)) {
        return [];
    }

    $originalPool = array_values($rankedGames);
    $pool = array_values($rankedGames);

    $dynamicElitePortfolio = app(DynamicElitePortfolioStrategy::class);

    if ($dynamicElitePortfolio->shouldHandle($quantidade, $this->tuning)) {
        $selected = $dynamicElitePortfolio->select($originalPool, $quantidade, $this->tuning);

        if (count($selected) >= min($quantidade, count($originalPool))) {
            app(EliteSelectionAuditService::class)->audit(
                $originalPool,
                $selected,
                $this->tuning,
                $quantidade
            );

            return array_slice($selected, 0, $quantidade);
        }
    }

    usort($pool, function ($a, $b) {
        return $this->rawPreservationValue($b) <=> $this->rawPreservationValue($a);
    });

    $selected = [];

    /*
    |--------------------------------------------------------------------------
    | HARD RAW LOCK
    |--------------------------------------------------------------------------
    | Regra absoluta:
    | Os melhores RAW entram SEM PASSAR por diversity, cluster ou filtros.
    | Isso impede que o portfolio destrua elite.
    */

    $smallQuantityMax = (int) $this->tuningValue('score_rank_guard.small_quantity_max', 15);
    $hardRawLockLimit = $quantidade <= $smallQuantityMax
        ? (int) $this->tuningValue('elite_lock.small_quantity_absolute_locked_limit', 2)
        : (int) $this->tuningValue('elite_lock.absolute_locked_limit', 4);

    $hardRawLockCount = min(
        $quantidade,
        max(0, $hardRawLockLimit)
    );

    $topRawCandidates = array_slice($pool, 0, $hardRawLockCount);

    foreach ($topRawCandidates as $candidate) {
        if (! $this->alreadySelected($candidate, $selected)) {
            $selected[] = $candidate;
        }
    }

    foreach ($this->extractHistoricalPeakLockedCandidates($originalPool, $selected, $quantidade) as $candidate) {
        if (! $this->tryAddCandidate($selected, $candidate, $quantidade, 'historical_peak_lock')) {
            continue;
        }
    }

    foreach ($this->extractShortPackageHighCeilingCandidates($originalPool, $selected, $quantidade) as $candidate) {
        if (! $this->tryAddCandidate($selected, $candidate, $quantidade, 'short_high_ceiling_guard')) {
            continue;
        }
    }

    foreach ($this->extractScoreRankGuardCandidates($originalPool, $selected, $quantidade) as $candidate) {
        if (! $this->tryAddCandidate($selected, $candidate, $quantidade, 'score_rank_guard')) {
            continue;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PIPELINE NORMAL
    |--------------------------------------------------------------------------
    */

    foreach (app(EliteSurvivalService::class)->extract($originalPool, $this->tuning, $quantidade) as $candidate) {
        if (! $this->tryAddCandidate($selected, $candidate, $quantidade, 'elite_survival')) {
            continue;
        }
    }

    foreach (app(ClusterEliteLockService::class)->extract($originalPool, $selected, $this->tuning, $quantidade) as $candidate) {
        if (! $this->tryAddCandidate($selected, $candidate, $quantidade, 'cluster_elite_lock')) {
            continue;
        }
    }

    foreach ($this->extractRankBandExplorationCandidates($originalPool, $selected, $quantidade) as $candidate) {
        if (! $this->tryAddCandidate($selected, $candidate, $quantidade, 'rank_band_exploration')) {
            continue;
        }
    }

    foreach ($this->extractOriginalRankingLockedCandidates($originalPool, $quantidade) as $candidate) {
        if (! $this->tryAddCandidate($selected, $candidate, $quantidade, 'original_lock')) {
            continue;
        }
    }

    foreach ($this->extractAbsoluteLockedCandidates($pool, $quantidade) as $candidate) {
        if (! $this->tryAddCandidate($selected, $candidate, $quantidade, 'absolute_lock')) {
            continue;
        }
    }

    foreach ($this->extractEliteLockedCandidates($pool, $selected, $quantidade) as $candidate) {
        if (! $this->tryAddCandidate($selected, $candidate, $quantidade, 'elite_lock')) {
            continue;
        }
    }

    foreach ($this->extractCorePreservedCandidates($pool, $selected, $quantidade) as $candidate) {
        if (! $this->tryAddCandidate($selected, $candidate, $quantidade, 'core_preservation')) {
            continue;
        }
    }

    foreach ($this->extractNearWinnerCandidates($pool, $selected, $quantidade) as $candidate) {
        if (! $this->tryAddCandidate($selected, $candidate, $quantidade, 'near_winner')) {
            continue;
        }
    }

    foreach ($this->extractRawKillerCandidates($pool, $quantidade) as $candidate) {
        if (! $this->tryAddCandidate($selected, $candidate, $quantidade, 'raw_killer')) {
            continue;
        }
    }

    $this->fillRemainingCandidates($selected, $pool, $quantidade);

    $selected = array_slice($selected, 0, $quantidade);

    app(EliteSelectionAuditService::class)->audit(
        $originalPool,
        $selected,
        $this->tuning,
        $quantidade
    );

    return $selected;
}

    protected function tryAddCandidate(array &$selected, array $candidate, int $quantidade, string $phase): bool
    {
        if (count($selected) >= $quantidade) {
            return false;
        }

        if ($this->alreadySelected($candidate, $selected)) {
            return false;
        }

        if (! $this->passesControlledDiversityGate($candidate, $selected, $phase)) {
            return false;
        }

        $selected[] = $candidate;

        return true;
    }

    protected function fillRemainingCandidates(array &$selected, array $pool, int $quantidade): void
    {
        if (count($selected) >= $quantidade) {
            return;
        }

        $fallbackCandidates = [];

        foreach ($pool as $candidate) {
            if ($this->alreadySelected($candidate, $selected)) {
                continue;
            }

            $candidate['fallback_rank_value'] = $this->lateElitePreservationValue($candidate, $selected);
            $fallbackCandidates[] = $candidate;
        }

        usort($fallbackCandidates, function (array $a, array $b): int {
            return ($b['fallback_rank_value'] ?? 0.0) <=> ($a['fallback_rank_value'] ?? 0.0);
        });

        foreach ($fallbackCandidates as $candidate) {
            if (count($selected) >= $quantidade) {
                return;
            }

            $this->tryAddCandidate($selected, $candidate, $quantidade, 'ranked_fallback');
        }

        foreach ($fallbackCandidates as $candidate) {
            if (count($selected) >= $quantidade) {
                return;
            }

            if (! $this->alreadySelected($candidate, $selected)) {
                $selected[] = $candidate;
            }
        }
    }

    protected function passesControlledDiversityGate(array $candidate, array $selected, string $phase): bool
    {
        if (empty($selected)) {
            return true;
        }

        $enabled = (bool) $this->tuningValue('controlled_diversity.enabled', true);

        if (! $enabled) {
            return true;
        }

        $maxOverlap = $this->maxOverlapWithSelected($candidate, $selected);

        if ($maxOverlap === 15) {
            return false;
        }

        if (in_array($phase, ['score_rank_guard', 'short_high_ceiling_guard', 'historical_peak_lock', 'dynamic_elite_portfolio'], true)) {
            return true;
        }

        $clone14Limit = (int) $this->tuningValue('controlled_diversity.max_overlap_14_count', 1);
        $clone13Limit = (int) $this->tuningValue('controlled_diversity.max_overlap_13_count', 3);

        if ($maxOverlap >= 14 && $this->countCandidatesWithOverlap($candidate, $selected, 14) >= $clone14Limit) {
            return $this->isCriticalEliteCandidate($candidate, $selected);
        }

        if ($maxOverlap >= 13 && $this->countCandidatesWithOverlap($candidate, $selected, 13) >= $clone13Limit) {
            return $this->isCriticalEliteCandidate($candidate, $selected);
        }

        if (count($selected) >= 2 && $maxOverlap > (int) $this->tuningValue('controlled_diversity.default_max_overlap_after_two', 13)) {
            return $this->isCriticalEliteCandidate($candidate, $selected);
        }

        return true;
    }

    protected function isCriticalEliteCandidate(array $candidate, array $selected): bool
    {
        $rawValue = $this->rawPreservationValue($candidate);
        $topSelectedRaw = 0.0;

        foreach ($selected as $selectedGame) {
            $topSelectedRaw = max($topSelectedRaw, $this->rawPreservationValue($selectedGame));
        }

        if ($topSelectedRaw <= 0) {
            return false;
        }

        $criticalThreshold = (float) $this->tuningValue('controlled_diversity.critical_raw_threshold', 1.03);

        if ($rawValue >= ($topSelectedRaw * $criticalThreshold)) {
            return true;
        }

        $score = (float) ($candidate['score'] ?? 0.0);
        $extremeScore = (float) ($candidate['extreme_score'] ?? 0.0);
        $statScore = (float) ($candidate['stat_score'] ?? 0.0);

        return $score >= (float) $this->tuningValue('controlled_diversity.critical_score_gate', 0.90)
            && $extremeScore >= (float) $this->tuningValue('controlled_diversity.critical_extreme_gate', 0.88)
            && $statScore >= (float) $this->tuningValue('controlled_diversity.critical_stat_gate', 0.86);
    }

    protected function countCandidatesWithOverlap(array $candidate, array $selected, int $minimumOverlap): int
    {
        $count = 0;

        foreach ($selected as $selectedGame) {
            if ($this->overlap($candidate, $selectedGame) >= $minimumOverlap) {
                $count++;
            }
        }

        return $count;
    }

    protected function extractOriginalRankingLockedCandidates(array $originalPool, int $quantidade): array
    {
        if (empty($originalPool)) {
            return [];
        }

        $limit = min(
            $quantidade,
            (int) $this->tuningValue('elite_lock.original_ranking_locked_limit', 3)
        );

        return array_slice($originalPool, 0, $limit);
    }

    protected function extractRankBandExplorationCandidates(array $originalPool, array $selected, int $quantidade): array
    {
        if (
            empty($originalPool)
            || ! (bool) $this->tuningValue('rank_band_exploration.enabled', true)
            || $quantidade < (int) $this->tuningValue('rank_band_exploration.min_quantity', 20)
        ) {
            return [];
        }

        $slots = min(
            max(1, (int) floor($quantidade * (float) $this->tuningValue('rank_band_exploration.slot_ratio', 0.18))),
            (int) $this->tuningValue('rank_band_exploration.max_slots', 18)
        );

        $bands = $this->tuningValue('rank_band_exploration.bands', [
            0.08,
            0.14,
            0.20,
            0.28,
            0.36,
            0.46,
            0.58,
            0.72,
        ]);

        if (! is_array($bands)) {
            return [];
        }

        $window = max(1, (int) $this->tuningValue('rank_band_exploration.window', 8));
        $poolCount = count($originalPool);
        $candidates = [];
        $seen = [];

        foreach ($bands as $band) {
            if (count($candidates) >= $slots) {
                break;
            }

            $center = (int) floor(($poolCount - 1) * max(0.0, min(1.0, (float) $band)));
            $start = max(0, $center - $window);
            $slice = array_slice($originalPool, $start, ($window * 2) + 1);

            usort($slice, function (array $a, array $b) use ($selected): int {
                return $this->portfolioExpansionValue($b, $selected)
                    <=>
                    $this->portfolioExpansionValue($a, $selected);
            });

            foreach ($slice as $candidate) {
                $key = $this->candidateKey($candidate);

                if (isset($seen[$key]) || $this->alreadySelected($candidate, $selected)) {
                    continue;
                }

                $candidate['rank_band_exploration'] = true;
                $candidate['rank_band_value'] = $this->portfolioExpansionValue($candidate, $selected);

                $candidates[] = $candidate;
                $seen[$key] = true;

                break;
            }
        }

        usort($candidates, function (array $a, array $b): int {
            return ($b['rank_band_value'] ?? 0.0) <=> ($a['rank_band_value'] ?? 0.0);
        });

        return array_slice($candidates, 0, $slots);
    }

    protected function extractScoreRankGuardCandidates(array $originalPool, array $selected, int $quantidade): array
    {
        if (
            empty($originalPool)
            || ! (bool) $this->tuningValue('score_rank_guard.enabled', true)
            || $quantidade < (int) $this->tuningValue('score_rank_guard.min_quantity', 1)
        ) {
            return [];
        }

        $smallQuantityMax = (int) $this->tuningValue('score_rank_guard.small_quantity_max', 15);
        $minimumSlots = $quantidade <= $smallQuantityMax
            ? (int) $this->tuningValue('score_rank_guard.small_quantity_min_slots', 6)
            : 1;

        $slots = min(
            $quantidade,
            max($minimumSlots, (int) floor($quantidade * (float) $this->tuningValue('score_rank_guard.slot_ratio', 0.28))),
            (int) $this->tuningValue('score_rank_guard.max_slots', 16)
        );

        $defaultBands = [
            0.035,
            0.070,
            0.110,
            0.150,
            0.190,
            0.230,
            0.270,
            0.319,
            0.390,
            0.500,
            0.615,
            0.702,
        ];

        $smallQuantityBands = [
            0.035,
            0.319,
            0.615,
            0.702,
            0.110,
            0.230,
            0.500,
            0.850,
        ];

        $bands = $quantidade <= $smallQuantityMax
            ? $this->tuningValue('score_rank_guard.small_quantity_bands', $smallQuantityBands)
            : $this->tuningValue('score_rank_guard.bands', $defaultBands);

        if (! is_array($bands)) {
            return [];
        }

        $poolCount = count($originalPool);
        $window = max(0, (int) $this->tuningValue('score_rank_guard.window', 2));
        $anchorWindow = max(
            $window,
            (int) $this->tuningValue('score_rank_guard.anchor_window', 8)
        );
        $perBandLimit = max(1, (int) $this->tuningValue('score_rank_guard.per_band_limit', 1));
        $latePerBandLimit = max($perBandLimit, (int) $this->tuningValue('score_rank_guard.late_per_band_limit', 2));
        $lateBandThreshold = (float) $this->tuningValue('score_rank_guard.late_band_threshold', 0.50);
        $anchorSlotLimit = $quantidade <= $smallQuantityMax
            ? (int) $this->tuningValue('score_rank_guard.small_quantity_anchor_slot_limit', 7)
            : (int) $this->tuningValue('score_rank_guard.anchor_slot_limit', $slots);
        $candidates = [];
        $seen = [];
        $anchorCandidates = [];
        $bandCandidates = [];

        $anchorRanks = $quantidade <= $smallQuantityMax
            ? $this->tuningValue('score_rank_guard.small_quantity_anchor_ranks', [])
            : $this->tuningValue(
                'score_rank_guard.anchor_ranks',
                $this->tuningValue('score_rank_guard.small_quantity_anchor_ranks', [])
            );

        if (is_array($anchorRanks)) {
            foreach ($anchorRanks as $rank) {
                if (count($anchorCandidates) >= $anchorSlotLimit) {
                    break;
                }

                $index = max(0, min($poolCount - 1, ((int) $rank) - 1));
                $exactCandidate = $this->buildScoreRankGuardCandidate(
                    $originalPool,
                    $index,
                    $selected,
                    $poolCount,
                    (int) $rank,
                    null
                );

                if ($exactCandidate !== null) {
                    $key = $this->candidateKey($exactCandidate);

                    if (! isset($seen[$key])) {
                        $anchorCandidates[] = $exactCandidate;
                        $seen[$key] = true;

                        continue;
                    }
                }

                $slice = [];

                foreach ($this->nearbyRankIndexes($index, $poolCount, $anchorWindow) as $nearbyIndex) {
                    $candidate = $this->buildScoreRankGuardCandidate(
                        $originalPool,
                        $nearbyIndex,
                        $selected,
                        $poolCount,
                        (int) $rank,
                        null
                    );

                    if ($candidate === null) {
                        continue;
                    }

                    $key = $this->candidateKey($candidate);

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $slice[] = $candidate;
                }

                usort($slice, function (array $a, array $b): int {
                    return ($b['score_rank_guard_value'] ?? 0.0) <=> ($a['score_rank_guard_value'] ?? 0.0);
                });

                foreach ($slice as $candidate) {
                    $key = $this->candidateKey($candidate);

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $anchorCandidates[] = $candidate;
                    $seen[$key] = true;

                    break;
                }
            }
        }

        foreach ($bands as $band) {
            if ((count($anchorCandidates) + count($bandCandidates)) >= ($slots * 2)) {
                break;
            }

            $center = (int) round(($poolCount - 1) * max(0.0, min(1.0, (float) $band)));
            $bandLimit = ((float) $band >= $lateBandThreshold) ? $latePerBandLimit : $perBandLimit;
            $addedForBand = 0;

            foreach ($this->nearbyRankIndexes($center, $poolCount, $window) as $index) {
                if ($addedForBand >= $bandLimit) {
                    break;
                }

                $candidate = $this->buildScoreRankGuardCandidate(
                    $originalPool,
                    $index,
                    $selected,
                    $poolCount,
                    null,
                    (float) $band
                );

                if ($candidate === null) {
                    continue;
                }

                $key = $this->candidateKey($candidate);

                if (isset($seen[$key]) || $this->alreadySelected($candidate, $selected)) {
                    continue;
                }

                $bandCandidates[] = $candidate;
                $seen[$key] = true;
                $addedForBand++;
            }
        }

        return array_slice(array_merge($anchorCandidates, $bandCandidates), 0, $slots);
    }

    protected function extractHistoricalPeakLockedCandidates(array $originalPool, array $selected, int $quantidade): array
    {
        if (
            empty($originalPool)
            || ! (bool) $this->tuningValue('historical_peak_lock.enabled', true)
            || $quantidade > (int) $this->tuningValue('historical_peak_lock.max_quantity', 25)
        ) {
            return [];
        }

        $limit = min(
            max(0, $quantidade - count($selected)),
            max(0, (int) $this->tuningValue('historical_peak_lock.limit', 2))
        );

        if ($limit <= 0) {
            return [];
        }

        $rankWindow = min(
            count($originalPool),
            max(1, (int) $this->tuningValue('historical_peak_lock.rank_window', 700))
        );
        $minMaxHits = (int) $this->tuningValue('historical_peak_lock.min_historical_max_hits', 14);
        $minHits14Plus = (int) $this->tuningValue('historical_peak_lock.min_historical_14_plus', 1);
        $candidates = [];

        for ($index = 0; $index < $rankWindow; $index++) {
            $candidate = $originalPool[$index] ?? null;

            if (! is_array($candidate) || $this->alreadySelected($candidate, $selected)) {
                continue;
            }

            $historicalMaxHits = (int) ($candidate['historical_max_hits'] ?? 0);
            $historical14Plus = (int) ($candidate['historical_14_plus'] ?? 0);

            if ($historicalMaxHits < $minMaxHits || $historical14Plus < $minHits14Plus) {
                continue;
            }

            $candidate['historical_peak_lock'] = true;
            $candidate['historical_peak_lock_rank'] = $index + 1;
            $candidate['historical_peak_lock_value'] =
                $this->rawPreservationValue($candidate)
                + ((float) ($candidate['near_15_score'] ?? 0.0) * 120.0)
                + ($historicalMaxHits * 900.0)
                + ($historical14Plus * 650.0)
                - (($index + 1) * 0.8);

            $candidates[] = $candidate;
        }

        usort($candidates, function (array $a, array $b): int {
            return ($b['historical_peak_lock_value'] ?? 0.0) <=> ($a['historical_peak_lock_value'] ?? 0.0);
        });

        return array_slice($candidates, 0, $limit);
    }

    protected function extractShortPackageHighCeilingCandidates(array $originalPool, array $selected, int $quantidade): array
    {
        if (
            empty($originalPool)
            || ! (bool) $this->tuningValue('short_high_ceiling_guard.enabled', true)
            || $quantidade > (int) $this->tuningValue('short_high_ceiling_guard.max_quantity', 10)
        ) {
            return [];
        }

        $limit = min(
            max(0, $quantidade - count($selected)),
            max(0, (int) $this->tuningValue('short_high_ceiling_guard.limit', 3))
        );

        if ($limit <= 0) {
            return [];
        }

        $strategies = $this->tuningValue('short_high_ceiling_guard.strategies', [
            'elite_high_ceiling',
            'controlled_delay',
            'explosive_hybrid',
            'correlation_cluster',
            'anti_mean_high_ceiling',
            'historical_replay',
            'strategic_repeat',
        ]);

        if (! is_array($strategies) || empty($strategies)) {
            return [];
        }

        $strategyLookup = array_fill_keys($strategies, true);
        $poolLimit = min(
            count($originalPool),
            max(1, (int) $this->tuningValue('short_high_ceiling_guard.rank_window', 2600))
        );
        $perStrategyLimit = max(1, (int) $this->tuningValue('short_high_ceiling_guard.per_strategy_limit', 1));
        $candidates = [];
        $selectedCandidates = [];
        $seen = [];

        for ($index = 0; $index < $poolLimit; $index++) {
            $candidate = $originalPool[$index] ?? null;

            if (! is_array($candidate) || $this->alreadySelected($candidate, $selected)) {
                continue;
            }

            $strategy = (string) ($candidate['strategy'] ?? $candidate['profile'] ?? 'unknown');
            $profile = (string) ($candidate['profile'] ?? $strategy);

            if (! isset($strategyLookup[$strategy]) && ! isset($strategyLookup[$profile])) {
                continue;
            }

            $candidate['short_high_ceiling_guard'] = true;
            $candidate['short_high_ceiling_rank'] = $index + 1;
            $candidate['short_high_ceiling_value'] = $this->shortHighCeilingValue($candidate, $selected, $index + 1);

            $candidates[] = $candidate;
        }

        foreach ($this->extractShortPackageRankSweepCandidates($originalPool, $selected, $strategyLookup, $limit) as $candidate) {
            if (count($selectedCandidates) >= $limit) {
                break;
            }

            $key = $this->candidateKey($candidate);

            if (isset($seen[$key])) {
                continue;
            }

            $selectedCandidates[] = $candidate;
            $seen[$key] = true;
        }

        usort($candidates, function (array $a, array $b): int {
            return ($b['short_high_ceiling_value'] ?? 0.0) <=> ($a['short_high_ceiling_value'] ?? 0.0);
        });

        $strategyCounts = [];

        foreach ($selectedCandidates as $candidate) {
            $strategy = (string) ($candidate['strategy'] ?? $candidate['profile'] ?? 'unknown');
            $strategyCounts[$strategy] = ($strategyCounts[$strategy] ?? 0) + 1;
        }

        foreach ($candidates as $candidate) {
            if (count($selectedCandidates) >= $limit) {
                break;
            }

            $strategy = (string) ($candidate['strategy'] ?? $candidate['profile'] ?? 'unknown');
            $key = $this->candidateKey($candidate);

            if (isset($seen[$key]) || (($strategyCounts[$strategy] ?? 0) >= $perStrategyLimit)) {
                continue;
            }

            $selectedCandidates[] = $candidate;
            $seen[$key] = true;
            $strategyCounts[$strategy] = ($strategyCounts[$strategy] ?? 0) + 1;
        }

        foreach ($candidates as $candidate) {
            if (count($selectedCandidates) >= $limit) {
                break;
            }

            $key = $this->candidateKey($candidate);

            if (isset($seen[$key])) {
                continue;
            }

            $selectedCandidates[] = $candidate;
            $seen[$key] = true;
        }

        return $selectedCandidates;
    }

    protected function extractShortPackageRankSweepCandidates(
        array $originalPool,
        array $selected,
        array $strategyLookup,
        int $limit
    ): array {
        if (
            $limit <= 0
            || ! (bool) $this->tuningValue('short_high_ceiling_guard.rank_sweep_enabled', true)
        ) {
            return [];
        }

        $poolCount = count($originalPool);
        $sweepLimit = min(
            $limit,
            max(0, (int) $this->tuningValue('short_high_ceiling_guard.rank_sweep_limit', 4))
        );

        if ($poolCount === 0 || $sweepLimit <= 0) {
            return [];
        }

        $rankTargets = $this->tuningValue('short_high_ceiling_guard.rank_sweep_targets', [2180]);
        $bandTargets = $this->tuningValue('short_high_ceiling_guard.rank_sweep_bands', [0.50]);
        $strategyPriority = $this->tuningValue('short_high_ceiling_guard.rank_sweep_strategy_priority', [
            'controlled_delay',
            'elite_high_ceiling',
            'correlation_cluster',
            'explosive_hybrid',
            'historical_replay',
            'strategic_repeat',
            'anti_mean_high_ceiling',
        ]);

        if (! is_array($rankTargets)) {
            $rankTargets = [];
        }

        if (is_array($bandTargets)) {
            foreach ($bandTargets as $band) {
                $rankTargets[] = ((int) round(($poolCount - 1) * max(0.0, min(1.0, (float) $band)))) + 1;
            }
        }

        if (! is_array($strategyPriority) || empty($strategyPriority)) {
            $strategyPriority = array_keys($strategyLookup);
        }

        $strategyOrder = array_flip($strategyPriority);
        $window = max(1, (int) $this->tuningValue('short_high_ceiling_guard.rank_sweep_window', 14));
        $perTargetLimit = max(1, (int) $this->tuningValue('short_high_ceiling_guard.rank_sweep_per_target', 3));
        $sweepCandidates = [];
        $seen = [];

        foreach ($rankTargets as $targetRank) {
            if (count($sweepCandidates) >= $sweepLimit) {
                break;
            }

            $targetIndex = max(0, min($poolCount - 1, ((int) $targetRank) - 1));
            $slice = [];

            foreach ($this->nearbyRankIndexes($targetIndex, $poolCount, $window) as $index) {
                $candidate = $originalPool[$index] ?? null;

                if (! is_array($candidate) || $this->alreadySelected($candidate, $selected)) {
                    continue;
                }

                $strategy = (string) ($candidate['strategy'] ?? $candidate['profile'] ?? 'unknown');
                $profile = (string) ($candidate['profile'] ?? $strategy);

                if (! isset($strategyLookup[$strategy]) && ! isset($strategyLookup[$profile])) {
                    continue;
                }

                $candidate['short_high_ceiling_guard'] = true;
                $candidate['short_high_ceiling_rank_sweep'] = true;
                $candidate['short_high_ceiling_rank_sweep_target'] = (int) $targetRank;
                $candidate['short_high_ceiling_rank'] = $index + 1;
                $candidate['short_high_ceiling_value'] = $this->shortHighCeilingValue($candidate, $selected, $index + 1);

                $slice[] = $candidate;
            }

            usort($slice, function (array $a, array $b) use ($strategyOrder): int {
                $strategyA = (string) ($a['strategy'] ?? $a['profile'] ?? 'unknown');
                $strategyB = (string) ($b['strategy'] ?? $b['profile'] ?? 'unknown');
                $orderA = $strategyOrder[$strategyA] ?? PHP_INT_MAX;
                $orderB = $strategyOrder[$strategyB] ?? PHP_INT_MAX;

                if ($orderA !== $orderB) {
                    return $orderA <=> $orderB;
                }

                return ((int) ($a['short_high_ceiling_rank'] ?? PHP_INT_MAX))
                    <=>
                    ((int) ($b['short_high_ceiling_rank'] ?? PHP_INT_MAX));
            });

            $addedForTarget = 0;

            foreach ($slice as $candidate) {
                if (count($sweepCandidates) >= $sweepLimit || $addedForTarget >= $perTargetLimit) {
                    break;
                }

                $key = $this->candidateKey($candidate);

                if (isset($seen[$key])) {
                    continue;
                }

                $sweepCandidates[] = $candidate;
                $seen[$key] = true;
                $addedForTarget++;
            }
        }

        return $sweepCandidates;
    }

    protected function buildScoreRankGuardCandidate(
        array $originalPool,
        int $index,
        array $selected,
        int $poolCount,
        ?int $anchorRank,
        ?float $band
    ): ?array {
        $candidate = $originalPool[$index] ?? null;

        if (! is_array($candidate) || $this->alreadySelected($candidate, $selected)) {
            return null;
        }

        $candidate['score_rank_guard'] = true;
        $candidate['score_rank_guard_rank'] = $index + 1;

        if ($anchorRank !== null) {
            $candidate['score_rank_guard_anchor_rank'] = $anchorRank;
        }

        if ($band !== null) {
            $candidate['score_rank_guard_band'] = $band;
        }

        $candidate['score_rank_guard_value'] = $this->scoreRankGuardValue(
            $candidate,
            $selected,
            $index + 1,
            $poolCount,
            $anchorRank,
            $band
        );

        return $candidate;
    }

    protected function scoreRankGuardValue(
        array $candidate,
        array $selected,
        int $rank,
        int $poolCount,
        ?int $anchorRank,
        ?float $band
    ): float {
        $value = $this->shortHighCeilingValue($candidate, $selected, $rank);

        if ($anchorRank !== null) {
            $distance = abs($rank - $anchorRank);
            $value += max(0.0, 350.0 - ($distance * 28.0));
        }

        if ($band !== null && $poolCount > 0) {
            $centerRank = ((int) round(($poolCount - 1) * max(0.0, min(1.0, $band)))) + 1;
            $distance = abs($rank - $centerRank);
            $value += max(0.0, 260.0 - ($distance * 14.0));
        }

        return $value;
    }

    protected function shortHighCeilingValue(array $candidate, array $selected, int $rank): float
    {
        $strategy = (string) ($candidate['strategy'] ?? $candidate['profile'] ?? 'unknown');
        $strategyBoosts = $this->tuningValue('short_high_ceiling_guard.strategy_boosts', []);
        $strategyBoost = is_array($strategyBoosts)
            ? (float) ($strategyBoosts[$strategy] ?? 0.0)
            : 0.0;

        $historicalMaxHits = (int) ($candidate['historical_max_hits'] ?? 0);
        $historical14Plus = (int) ($candidate['historical_14_plus'] ?? 0);

        $value = $this->portfolioExpansionValue($candidate, $selected) * 0.22;
        $value += (float) ($candidate['near_15_score'] ?? 0.0) * 22.0;
        $value += (float) ($candidate['ceiling_score'] ?? 0.0) * 16.0;
        $value += (float) ($candidate['historical_peak_score'] ?? 0.0) * 220.0;
        $value += $historicalMaxHits * 34.0;
        $value += $historical14Plus * 80.0;
        $value += $strategyBoost;

        if ($rank > 0) {
            $value += min(240.0, log($rank + 1, 2) * 18.0);
        }

        return $value;
    }

    protected function nearbyRankIndexes(int $center, int $poolCount, int $window): array
    {
        $indexes = [];

        for ($offset = 0; $offset <= $window; $offset++) {
            foreach ([$center - $offset, $center + $offset] as $index) {
                if ($index < 0 || $index >= $poolCount || isset($indexes[$index])) {
                    continue;
                }

                $indexes[$index] = $index;
            }
        }

        return array_values($indexes);
    }

    protected function extractAbsoluteLockedCandidates(array $pool, int $quantidade): array
    {
        if (empty($pool)) {
            return [];
        }

        $limit = min(
            $quantidade,
            (int) $this->tuningValue('elite_lock.absolute_locked_limit', 2)
        );

        return array_slice($pool, 0, $limit);
    }

    protected function extractEliteLockedCandidates(array $pool, array $selected, int $quantidade): array
    {
        if (empty($pool)) {
            return [];
        }

        $topValue = $this->rawPreservationValue($pool[0]);

        if ($topValue <= 0) {
            return [];
        }

        $threshold = $topValue * (float) $this->tuningValue('elite_lock.elite_threshold', 0.90);
        $eliteLimit = (int) $this->tuningValue('elite_lock.elite_limit', 1);
        $eliteCandidates = [];

        foreach ($pool as $candidate) {
            if ($this->alreadySelected($candidate, $selected)) {
                continue;
            }

            if ($this->rawPreservationValue($candidate) >= $threshold) {
                $eliteCandidates[] = $candidate;
            }
        }

        usort($eliteCandidates, function ($a, $b) {
            return $this->rawPreservationValue($b) <=> $this->rawPreservationValue($a);
        });

        return array_slice($eliteCandidates, 0, $eliteLimit);
    }

    protected function extractCorePreservedCandidates(array $pool, array $selected, int $quantidade): array
    {
        if (empty($pool) || empty($selected)) {
            return [];
        }

        $coreCandidates = [];
        $minOverlap = (int) $this->tuningValue('core_preservation.min_overlap', 12);
        $rawBoost = (float) $this->tuningValue('core_preservation.raw_boost', 0.35);

        foreach ($pool as $candidate) {
            if ($this->alreadySelected($candidate, $selected)) {
                continue;
            }

            $maxOverlap = $this->maxOverlapWithSelected($candidate, $selected);
            $score = (float) ($candidate['score'] ?? 0.0);
            $extremeScore = (float) ($candidate['extreme_score'] ?? 0.0);
            $statScore = (float) ($candidate['stat_score'] ?? 0.0);
            $repeatCount = (int) ($candidate['repetidas_ultimo_concurso'] ?? 0);
            $cycleHits = (int) ($candidate['cycle_hits'] ?? 0);
            $sum = (int) ($candidate['soma'] ?? 0);
            $oddCount = (int) ($candidate['impares'] ?? 0);
            $sequence = (int) ($candidate['analise']['sequencia_maxima'] ?? 0);

            if (
                $maxOverlap >= $minOverlap &&
                $repeatCount >= 7 &&
                $repeatCount <= 12 &&
                $cycleHits >= 1 &&
                $sum >= 150 &&
                $sum <= 235 &&
                $oddCount >= 5 &&
                $oddCount <= 10 &&
                $sequence >= 2 &&
                $sequence <= 8 &&
                ($score > 0 || $extremeScore > 0 || $statScore > 0)
            ) {
                $coreCandidates[] = $candidate;
            }
        }

        usort($coreCandidates, function ($a, $b) use ($selected, $rawBoost) {
            return ($this->corePreservationValue($b, $selected) + ($this->rawPreservationValue($b) * $rawBoost))
                <=>
                ($this->corePreservationValue($a, $selected) + ($this->rawPreservationValue($a) * $rawBoost));
        });

        return array_slice($coreCandidates, 0, max(1, $quantidade - count($selected)));
    }

    protected function extractNearWinnerCandidates(array $pool, array $selected, int $quantidade): array
    {
        if (empty($pool) || empty($selected)) {
            return [];
        }

        $scores = array_map(
            fn ($candidate) => (float) ($candidate['score'] ?? 0.0),
            $pool
        );

        $extremeScores = array_map(
            fn ($candidate) => (float) ($candidate['extreme_score'] ?? 0.0),
            $pool
        );

        $statScores = array_map(
            fn ($candidate) => (float) ($candidate['stat_score'] ?? 0.0),
            $pool
        );

        rsort($scores);
        rsort($extremeScores);
        rsort($statScores);

        $topScore = $scores[0] ?? 0.0;
        $topExtreme = $extremeScores[0] ?? 0.0;
        $topStat = $statScores[0] ?? 0.0;

        $nearWinners = [];

        foreach ($pool as $candidate) {
            if ($this->alreadySelected($candidate, $selected)) {
                continue;
            }

            $score = (float) ($candidate['score'] ?? 0.0);
            $extremeScore = (float) ($candidate['extreme_score'] ?? 0.0);
            $statScore = (float) ($candidate['stat_score'] ?? 0.0);
            $repeatCount = (int) ($candidate['repetidas_ultimo_concurso'] ?? 0);
            $cycleHits = (int) ($candidate['cycle_hits'] ?? 0);
            $sum = (int) ($candidate['soma'] ?? 0);
            $oddCount = (int) ($candidate['impares'] ?? 0);
            $sequence = (int) ($candidate['analise']['sequencia_maxima'] ?? 0);

            $maxOverlap = $this->maxOverlapWithSelected($candidate, $selected);

            $isNearWinner = false;

            if (
                $maxOverlap >= 12 &&
                $score >= ($topScore * (float) $this->tuningValue('near_winner.score_threshold', 0.82)) &&
                $extremeScore >= ($topExtreme * (float) $this->tuningValue('near_winner.extreme_threshold', 0.80))
            ) {
                $isNearWinner = true;
            }

            if (
                $maxOverlap >= 11 &&
                $score >= ($topScore * 0.86) &&
                $statScore >= ($topStat * (float) $this->tuningValue('near_winner.stat_threshold', 0.82)) &&
                $repeatCount >= 7 &&
                $repeatCount <= 12
            ) {
                $isNearWinner = true;
            }

            if (
                $maxOverlap >= 11 &&
                $extremeScore >= ($topExtreme * 0.78) &&
                $repeatCount >= 7 &&
                $repeatCount <= 12 &&
                $cycleHits >= 1 &&
                $sum >= 150 &&
                $sum <= 235 &&
                $oddCount >= 5 &&
                $oddCount <= 10 &&
                $sequence >= 2 &&
                $sequence <= 8
            ) {
                $isNearWinner = true;
            }

            if ($isNearWinner) {
                $nearWinners[] = $candidate;
            }
        }

        usort($nearWinners, function ($a, $b) {
            return $this->rawPreservationValue($b) <=> $this->rawPreservationValue($a);
        });

        return array_slice($nearWinners, 0, max(1, $quantidade - count($selected)));
    }

    protected function extractRawKillerCandidates(array $pool, int $quantidade): array
    {
        if (empty($pool)) {
            return [];
        }

        $scores = array_map(
            fn ($candidate) => (float) ($candidate['score'] ?? 0.0),
            $pool
        );

        $extremeScores = array_map(
            fn ($candidate) => (float) ($candidate['extreme_score'] ?? 0.0),
            $pool
        );

        $statScores = array_map(
            fn ($candidate) => (float) ($candidate['stat_score'] ?? 0.0),
            $pool
        );

        rsort($scores);
        rsort($extremeScores);
        rsort($statScores);

        $topScore = $scores[0] ?? 0.0;
        $topExtreme = $extremeScores[0] ?? 0.0;
        $topStat = $statScores[0] ?? 0.0;

        $scoreThreshold = $topScore * (float) $this->tuningValue('raw_killer.score_threshold', 0.90);
        $extremeThreshold = $topExtreme * (float) $this->tuningValue('raw_killer.extreme_threshold', 0.88);
        $statThreshold = $topStat * (float) $this->tuningValue('raw_killer.stat_threshold', 0.86);

        $killers = [];

        foreach ($pool as $candidate) {
            $score = (float) ($candidate['score'] ?? 0.0);
            $extremeScore = (float) ($candidate['extreme_score'] ?? 0.0);
            $statScore = (float) ($candidate['stat_score'] ?? 0.0);
            $repeatCount = (int) ($candidate['repetidas_ultimo_concurso'] ?? 0);
            $cycleHits = (int) ($candidate['cycle_hits'] ?? 0);
            $sum = (int) ($candidate['soma'] ?? 0);
            $oddCount = (int) ($candidate['impares'] ?? 0);
            $sequence = (int) ($candidate['analise']['sequencia_maxima'] ?? 0);

            $isKiller = false;

            if (
                $score >= $scoreThreshold &&
                $extremeScore >= $extremeThreshold
            ) {
                $isKiller = true;
            }

            if (
                $score >= ($topScore * (float) $this->tuningValue('raw_killer.fallback_score_threshold', 0.86)) &&
                $statScore >= $statThreshold &&
                $repeatCount >= 7 &&
                $repeatCount <= 12
            ) {
                $isKiller = true;
            }

            if (
                $extremeScore >= ($topExtreme * (float) $this->tuningValue('raw_killer.fallback_extreme_threshold', 0.84)) &&
                $repeatCount >= 8 &&
                $repeatCount <= 11 &&
                $cycleHits >= 1 &&
                $sum >= 150 &&
                $sum <= 235 &&
                $oddCount >= 5 &&
                $oddCount <= 10 &&
                $sequence >= 2 &&
                $sequence <= 8
            ) {
                $isKiller = true;
            }

            if ($isKiller) {
                $killers[] = $candidate;
            }
        }

        usort($killers, function ($a, $b) {
            return $this->rawPreservationValue($b) <=> $this->rawPreservationValue($a);
        });

        return array_slice($killers, 0, max(1, $quantidade - 2));
    }

    protected function lateElitePreservationValue(array $candidate, array $selected): float
    {
        $rawValue = $this->rawPreservationValue($candidate);

        if (empty($selected)) {
            return $this->portfolioExpansionValue($candidate, $selected);
        }

        $topSelectedRaw = max(array_map(
            fn ($game) => $this->rawPreservationValue($game),
            $selected
        ));

        $lateEliteThreshold = (float) $this->tuningValue('elite_lock.late_elite_threshold', 0.94);
        $lateEliteMultiplier = (float) $this->tuningValue('elite_lock.late_elite_multiplier', 100.0);

        if ($topSelectedRaw > 0 && $rawValue >= ($topSelectedRaw * $lateEliteThreshold)) {
            return $rawValue * $lateEliteMultiplier;
        }

        return $this->portfolioExpansionValue($candidate, $selected);
    }

    protected function eliteOverrideValue(array $candidate, array $selected): ?float
    {
        $rawValue = $this->rawPreservationValue($candidate);

        if ($rawValue <= 0 || empty($selected)) {
            return null;
        }

        $topRaw = 0.0;

        foreach ($selected as $game) {
            $topRaw = max($topRaw, $this->rawPreservationValue($game));
        }

        if ($topRaw <= 0) {
            return null;
        }

        $threshold = $topRaw * (float) $this->tuningValue('elite_override.threshold', 0.985);

        $score = (float) ($candidate['score'] ?? 0.0);
        $extremeScore = (float) ($candidate['extreme_score'] ?? 0.0);
        $statScore = (float) ($candidate['stat_score'] ?? 0.0);

        $scoreGate = (float) $this->tuningValue('elite_override.score_gate', 0.80);
        $extremeGate = (float) $this->tuningValue('elite_override.extreme_gate', 0.78);
        $statGate = (float) $this->tuningValue('elite_override.stat_gate', 0.75);

        if (
            $rawValue >= $threshold &&
            $score >= $scoreGate &&
            $extremeScore >= $extremeGate &&
            $statScore >= $statGate
        ) {
            return $rawValue * (float) $this->tuningValue('elite_override.multiplier', 9999.0);
        }

        return null;
    }

    protected function portfolioExpansionValue(array $candidate, array $selected): float
    {
        $eliteOverride = $this->eliteOverrideValue($candidate, $selected);

        if ($eliteOverride !== null) {
            return $eliteOverride;
        }

        $rawValue = $this->rawPreservationValue($candidate);
        $diversityValue = $this->diversityValue($candidate, $selected);
        $coverageValue = $this->coverageValue($candidate, $selected);
        $clonePenalty = $this->clonePenalty($candidate, $selected);
        $coreBonus = $this->coreBonus($candidate, $selected);

        $rawWeight = (float) $this->tuningValue('portfolio_expansion.raw_weight', 0.985);
        $diversityWeight = (float) $this->tuningValue('portfolio_expansion.diversity_weight', 0.004);
        $coverageWeight = (float) $this->tuningValue('portfolio_expansion.coverage_weight', 0.003);
        $coreBonusMultiplier = (float) $this->tuningValue('portfolio_expansion.core_bonus_multiplier', 1.30);

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

    protected function corePreservationValue(array $candidate, array $selected): float
    {
        $rawValue = $this->rawPreservationValue($candidate);
        $maxOverlap = $this->maxOverlapWithSelected($candidate, $selected);
        $clonePenalty = $this->clonePenalty($candidate, $selected);

        return $rawValue + ($maxOverlap * 90) - ($clonePenalty * 0.20);
    }

    protected function coreBonus(array $candidate, array $selected): float
    {
        $maxOverlap = $this->maxOverlapWithSelected($candidate, $selected);

        if ($maxOverlap >= 14) {
            return (float) $this->tuningValue('core_bonus.overlap_14', 380.0);
        }

        if ($maxOverlap === 13) {
            return (float) $this->tuningValue('core_bonus.overlap_13', 240.0);
        }

        if ($maxOverlap === 12) {
            return (float) $this->tuningValue('core_bonus.overlap_12', 120.0);
        }

        if ($maxOverlap === 11) {
            return (float) $this->tuningValue('core_bonus.overlap_11', 35.0);
        }

        if ($maxOverlap === 10) {
            return (float) $this->tuningValue('core_bonus.overlap_10', 0.0);
        }

        return 0.0;
    }

    protected function diversityValue(array $candidate, array $selected): float
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

        return $averageDistance * (float) $this->tuningValue('diversity.average_distance_multiplier', 7.0);
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

    protected function clonePenalty(array $candidate, array $selected): float
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
                $penalty += (float) $this->tuningValue('clone_penalty.overlap_15', 999.0);
            } elseif ($intersection >= 14) {
                $penalty += (float) $this->tuningValue('clone_penalty.overlap_14', 2.0);
            } elseif ($intersection === 13) {
                $penalty += (float) $this->tuningValue('clone_penalty.overlap_13', 0.7);
            } elseif ($intersection <= (int) $this->tuningValue('clone_penalty.low_overlap_limit', 6)) {
                $penalty += (float) $this->tuningValue('clone_penalty.low_overlap_penalty', 8.0);
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

    protected function overlap(array $candidate, array $selectedGame): int
    {
        return count(array_intersect($candidate['dezenas'] ?? [], $selectedGame['dezenas'] ?? []));
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

    protected function tuningValue(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->tuning;

        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
