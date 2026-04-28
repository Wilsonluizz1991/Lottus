<?php

namespace App\Services\Lottus\Generation;

class ClusterEliteLockService
{
    public function extract(
        array $rankedGames,
        array $selected,
        array $tuning,
        int $quantidade
    ): array {
        if (empty($rankedGames)) {
            return [];
        }

        $pool = array_values($rankedGames);

        $limit = min(
            max($quantidade * 10, 50),
            count($pool)
        );

        $pool = array_slice($pool, 0, $limit);

        $clusters = $this->buildClusters($pool);

        $result = [];
        $seen = [];

        foreach ($clusters as $cluster) {
            foreach ($cluster as $candidate) {
                $key = $this->candidateKey($candidate);

                if (isset($seen[$key])) {
                    continue;
                }

                $candidate['cluster_elite_score'] = $this->clusterEliteValue($candidate, $cluster);

                $result[] = $candidate;
                $seen[$key] = true;
            }
        }

        usort($result, function ($a, $b) {
            $scoreA = (float) ($a['cluster_elite_score'] ?? 0);
            $scoreB = (float) ($b['cluster_elite_score'] ?? 0);

            if ($scoreA === $scoreB) {
                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            }

            return $scoreB <=> $scoreA;
        });

        return $result;
    }

    protected function buildClusters(array $pool): array
    {
        $clusters = [];

        foreach ($pool as $candidate) {
            $inserted = false;

            foreach ($clusters as &$cluster) {
                if ($this->belongsToCluster($candidate, $cluster)) {
                    $cluster[] = $candidate;
                    $inserted = true;
                    break;
                }
            }

            if (! $inserted) {
                $clusters[] = [$candidate];
            }
        }

        return $clusters;
    }

    protected function belongsToCluster(array $candidate, array $cluster): bool
    {
        $candidateNumbers = $candidate['dezenas'] ?? [];

        foreach ($cluster as $existing) {
            $existingNumbers = $existing['dezenas'] ?? [];

            $overlap = count(array_intersect($candidateNumbers, $existingNumbers));

            if ($overlap >= 11) {
                return true;
            }
        }

        return false;
    }

    protected function clusterEliteValue(array $candidate, array $cluster): float
    {
        $score = (float) ($candidate['score'] ?? 0.0);
        $extremeScore = (float) ($candidate['extreme_score'] ?? 0.0);
        $statScore = (float) ($candidate['stat_score'] ?? 0.0);

        $repeatCount = (int) ($candidate['repetidas_ultimo_concurso'] ?? 0);
        $cycleHits = (int) ($candidate['cycle_hits'] ?? 0);

        $clusterStrength = (float) ($candidate['analise']['cluster_strength'] ?? 0.0);
        $correlationQuality = (float) ($candidate['analise']['correlation_quality'] ?? 0.0);
        $repeatQuality = (float) ($candidate['analise']['repeat_quality'] ?? 0.0);

        $value = 0.0;

        $value += $score * 0.65;
        $value += $extremeScore * 0.20;
        $value += $statScore * 0.10;

        $clusterSize = count($cluster);

        if ($clusterSize >= 8) {
            $value += 120;
        } elseif ($clusterSize >= 5) {
            $value += 80;
        } elseif ($clusterSize >= 3) {
            $value += 45;
        }

        if ($repeatCount >= 8 && $repeatCount <= 11) {
            $value += 90;
        }

        if ($cycleHits >= 3) {
            $value += 60;
        }

        if ($clusterStrength >= 8) {
            $value += 80;
        }

        if (
            $correlationQuality >= 0.70 &&
            $repeatQuality >= 0.80
        ) {
            $value += 140;
        }

        return $value;
    }

    protected function candidateKey(array $candidate): string
    {
        $dezenas = $candidate['dezenas'] ?? [];

        $dezenas = array_values(array_unique(array_map('intval', $dezenas)));

        sort($dezenas);

        return implode('-', $dezenas);
    }
}