<?php

namespace App\Services\Lottus\MainLearning;

class LottusMainPortfolioCalibrationService
{
    public function calibrate(array $summary, array $previousPayload = []): array
    {
        $diagnostics = $summary['diagnostico'] ?? [];

        if (empty($diagnostics)) {
            return $this->emptyCalibration('sem_diagnostico');
        }

        $eliteRows = [];
        $maxObservedRank = 0;
        $eliteStrategies = [];

        foreach ($diagnostics as $index => $row) {
            $raw = (int) ($row['raw'] ?? 0);
            $rank = (int) ($row['raw_rank'] ?? 0);
            $selectedRanks = array_map('intval', (array) ($row['selected_ranks'] ?? []));

            $maxObservedRank = max($maxObservedRank, $rank, ...$selectedRanks);

            if ($raw < 14 || $rank < 1) {
                continue;
            }

            $loss = max(0, (int) ($row['loss'] ?? 0));
            $strategy = (string) ($row['raw_strategy'] ?? '');

            if ($strategy !== '') {
                $eliteStrategies[$strategy] = true;
            }

            $eliteRows[] = [
                'rank' => $rank,
                'raw' => $raw,
                'loss' => $loss,
                'strategy' => $strategy,
                'weight' => $this->rowWeight($row, $index, count($diagnostics)),
            ];
        }

        if (empty($eliteRows)) {
            return $this->emptyCalibration('sem_raw_14');
        }

        $clusters = $this->clusterRanks($eliteRows);
        $absoluteTargets = $this->absoluteTargets($clusters);
        $bands = $this->bandsFromTargets(
            $absoluteTargets,
            max(1, $maxObservedRank),
            (array) ($previousPayload['portfolio_rules']['dynamic_elite_portfolio']['conversion_sweep']['bands'] ?? [])
        );

        return [
            'portfolio_rules' => [
                'dynamic_elite_portfolio' => [
                    'conversion_sweep' => [
                        'enabled' => true,
                        'shortlist_quantity' => 10,
                        'window' => 1200,
                        'strategies' => $this->conversionStrategies(array_keys($eliteStrategies)),
                        'absolute_rank_targets' => $absoluteTargets,
                        'bands' => $bands,
                        'family_min_band' => 0.25,
                        'family_window' => 320,
                        'family_per_band' => 0,
                        'family_radii' => [16, 32, 96, 64, 128, 192, 256],
                        'family_cluster_radius_min' => 48,
                        'family_cluster_window' => 16,
                        'family_cluster_size' => 1,
                        'min_target_distance' => 48,
                    ],
                ],
            ],
            'metrics' => [
                'reason' => 'raw_14_rank_loss_calibration',
                'raw_elite_rows' => count($eliteRows),
                'loss_rows' => count(array_filter($eliteRows, fn (array $row) => (int) $row['loss'] > 0)),
                'absolute_targets' => $absoluteTargets,
                'bands' => $bands,
                'max_observed_rank' => $maxObservedRank,
            ],
        ];
    }

    protected function rowWeight(array $row, int $index, int $total): float
    {
        $raw = (int) ($row['raw'] ?? 0);
        $loss = max(0, (int) ($row['loss'] ?? 0));
        $recency = $total > 1 ? (($index + 1) / $total) : 1.0;

        return 1.0
            + ($raw === 15 ? 2.0 : 0.0)
            + ($loss * 0.55)
            + ($recency * 0.35);
    }

    protected function clusterRanks(array $rows): array
    {
        usort($rows, fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        $window = max(80, (int) config('lottus_main_learning.portfolio_calibration.rank_cluster_window', 420));
        $clusters = [];

        foreach ($rows as $row) {
            $rank = (int) $row['rank'];
            $lastIndex = count($clusters) - 1;

            if ($lastIndex >= 0 && abs($rank - (int) $clusters[$lastIndex]['center']) <= $window) {
                $clusters[$lastIndex]['rows'][] = $row;
                $clusters[$lastIndex]['center'] = $this->weightedCenter($clusters[$lastIndex]['rows']);
                $clusters[$lastIndex]['weight'] = array_sum(array_column($clusters[$lastIndex]['rows'], 'weight'));
                $clusters[$lastIndex]['loss'] = array_sum(array_column($clusters[$lastIndex]['rows'], 'loss'));

                continue;
            }

            $clusters[] = [
                'center' => $rank,
                'rows' => [$row],
                'weight' => (float) $row['weight'],
                'loss' => (int) $row['loss'],
            ];
        }

        usort($clusters, function (array $a, array $b): int {
            $score = ((float) ($b['weight'] ?? 0.0) + ((int) ($b['loss'] ?? 0) * 0.8))
                <=>
                ((float) ($a['weight'] ?? 0.0) + ((int) ($a['loss'] ?? 0) * 0.8));

            if ($score !== 0) {
                return $score;
            }

            return ((int) ($a['center'] ?? 0)) <=> ((int) ($b['center'] ?? 0));
        });

        return $clusters;
    }

    protected function weightedCenter(array $rows): int
    {
        $weighted = 0.0;
        $weight = 0.0;

        foreach ($rows as $row) {
            $rowWeight = max(0.1, (float) ($row['weight'] ?? 1.0));
            $weighted += ((int) ($row['rank'] ?? 0) * $rowWeight);
            $weight += $rowWeight;
        }

        return (int) round($weighted / max(0.1, $weight));
    }

    protected function absoluteTargets(array $clusters): array
    {
        $limit = max(1, (int) config('lottus_main_learning.portfolio_calibration.max_absolute_targets', 8));
        $targets = [];

        foreach (array_slice($clusters, 0, $limit) as $cluster) {
            $rank = max(1, (int) ($cluster['center'] ?? 0));

            $targets[] = [
                'rank' => $rank,
                'weight' => round((float) ($cluster['weight'] ?? 1.0), 4),
                'loss' => (int) ($cluster['loss'] ?? 0),
            ];
        }

        usort($targets, fn (array $a, array $b): int => ((int) $a['rank']) <=> ((int) $b['rank']));

        return $targets;
    }

    protected function conversionStrategies(array $observedStrategies): array
    {
        $defaults = [
            'single_swap_sweep',
            'baseline_explosive',
            'correlation_cluster',
            'controlled_delay',
            'elite_high_ceiling',
            'near15_mutation',
            'strategic_repeat',
            'explosive_hybrid',
            'anti_mean_high_ceiling',
            'historical_replay',
            'trend_adaptive_core',
            'pair_lattice_core',
            'forced_pair_synergy_core',
            'deterministic_high_ceiling',
            'historical_elite_mutation',
            'double_swap_sweep',
        ];

        return array_values(array_unique(array_filter(array_merge($observedStrategies, $defaults))));
    }

    protected function bandsFromTargets(array $targets, int $maxObservedRank, array $previousBands): array
    {
        $baseBands = [0.02, 0.16, 0.25, 0.40, 0.49, 0.82, 0.96];
        $bands = array_merge($baseBands, $previousBands);
        $denominator = max(1, (int) round($maxObservedRank / 0.97));

        foreach ($targets as $target) {
            $rank = (int) ($target['rank'] ?? 0);

            if ($rank < 1) {
                continue;
            }

            $bands[] = round(max(0.01, min(0.98, $rank / $denominator)), 4);
        }

        $bands = array_values(array_unique(array_map(
            fn ($band) => round((float) $band, 4),
            array_filter($bands, fn ($band) => (float) $band > 0.0 && (float) $band <= 1.0)
        )));
        sort($bands);

        return $bands;
    }

    protected function emptyCalibration(string $reason): array
    {
        return [
            'portfolio_rules' => [],
            'metrics' => [
                'reason' => $reason,
                'absolute_targets' => [],
                'bands' => [],
            ],
        ];
    }
}
