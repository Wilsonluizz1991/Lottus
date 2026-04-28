<?php

namespace App\Services\Lottus\Generation;

class CoreClusterPreservationService
{
    public function preserve(array $rankedGames, int $limit = 60): array
    {
        if (empty($rankedGames)) {
            return [];
        }

        $pool = array_slice(array_values($rankedGames), 0, $limit);

        $coreNumbers = $this->detectCoreNumbers($pool);
        $corePairs = $this->detectCorePairs($pool);
        $coreTriples = $this->detectCoreTriples($pool);

        foreach ($rankedGames as $index => $game) {
            $dezenas = $game['dezenas'] ?? [];

            if (count($dezenas) !== 15) {
                continue;
            }

            $coreScore = $this->calculateCoreScore($dezenas, $coreNumbers, $corePairs, $coreTriples);
            $preservationScore = $this->calculatePreservationScore($dezenas, $pool);

            $rankedGames[$index]['core_score'] = round($coreScore, 6);
            $rankedGames[$index]['preservation_score'] = round($preservationScore, 6);
            $rankedGames[$index]['score'] = round(((float) ($game['score'] ?? 0)) + $coreScore + $preservationScore, 6);
            $rankedGames[$index]['extreme_score'] = round(((float) ($game['extreme_score'] ?? 0)) + ($coreScore * 0.55), 6);

            $rankedGames[$index]['analise']['core_numbers'] = array_values($coreNumbers);
            $rankedGames[$index]['analise']['core_score'] = round($coreScore, 6);
            $rankedGames[$index]['analise']['preservation_score'] = round($preservationScore, 6);
        }

        usort($rankedGames, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $rankedGames;
    }

    protected function detectCoreNumbers(array $pool): array
    {
        $frequency = [];

        foreach ($pool as $index => $game) {
            $weight = $this->positionWeight($index);

            foreach (($game['dezenas'] ?? []) as $number) {
                $number = (int) $number;
                $frequency[$number] = ($frequency[$number] ?? 0) + $weight;
            }
        }

        arsort($frequency);

        return array_slice(array_keys($frequency), 0, 11);
    }

    protected function detectCorePairs(array $pool): array
    {
        $pairs = [];

        foreach ($pool as $index => $game) {
            $dezenas = $game['dezenas'] ?? [];
            sort($dezenas);

            $weight = $this->positionWeight($index);

            for ($i = 0; $i < count($dezenas); $i++) {
                for ($j = $i + 1; $j < count($dezenas); $j++) {
                    $key = $dezenas[$i] . '-' . $dezenas[$j];
                    $pairs[$key] = ($pairs[$key] ?? 0) + $weight;
                }
            }
        }

        arsort($pairs);

        return array_slice(array_keys($pairs), 0, 45);
    }

    protected function detectCoreTriples(array $pool): array
    {
        $triples = [];

        foreach ($pool as $index => $game) {
            $dezenas = $game['dezenas'] ?? [];
            sort($dezenas);

            $weight = $this->positionWeight($index);

            for ($i = 0; $i < count($dezenas); $i++) {
                for ($j = $i + 1; $j < count($dezenas); $j++) {
                    for ($k = $j + 1; $k < count($dezenas); $k++) {
                        $key = $dezenas[$i] . '-' . $dezenas[$j] . '-' . $dezenas[$k];
                        $triples[$key] = ($triples[$key] ?? 0) + $weight;
                    }
                }
            }
        }

        arsort($triples);

        return array_slice(array_keys($triples), 0, 80);
    }

    protected function calculateCoreScore(array $dezenas, array $coreNumbers, array $corePairs, array $coreTriples): float
    {
        sort($dezenas);

        $score = 0.0;

        $coreHits = count(array_intersect($dezenas, $coreNumbers));

        if ($coreHits >= 10) {
            $score += 80;
        } elseif ($coreHits === 9) {
            $score += 55;
        } elseif ($coreHits === 8) {
            $score += 32;
        } elseif ($coreHits === 7) {
            $score += 14;
        }

        $pairHits = 0;

        for ($i = 0; $i < count($dezenas); $i++) {
            for ($j = $i + 1; $j < count($dezenas); $j++) {
                $key = $dezenas[$i] . '-' . $dezenas[$j];

                if (in_array($key, $corePairs, true)) {
                    $pairHits++;
                }
            }
        }

        if ($pairHits >= 28) {
            $score += 75;
        } elseif ($pairHits >= 22) {
            $score += 48;
        } elseif ($pairHits >= 16) {
            $score += 24;
        }

        $tripleHits = 0;

        for ($i = 0; $i < count($dezenas); $i++) {
            for ($j = $i + 1; $j < count($dezenas); $j++) {
                for ($k = $j + 1; $k < count($dezenas); $k++) {
                    $key = $dezenas[$i] . '-' . $dezenas[$j] . '-' . $dezenas[$k];

                    if (in_array($key, $coreTriples, true)) {
                        $tripleHits++;
                    }
                }
            }
        }

        if ($tripleHits >= 18) {
            $score += 90;
        } elseif ($tripleHits >= 12) {
            $score += 55;
        } elseif ($tripleHits >= 8) {
            $score += 28;
        }

        return $score;
    }

    protected function calculatePreservationScore(array $dezenas, array $pool): float
    {
        $score = 0.0;

        foreach (array_slice($pool, 0, 12) as $index => $game) {
            $topGame = $game['dezenas'] ?? [];

            $overlap = count(array_intersect($dezenas, $topGame));
            $weight = $this->positionWeight($index);

            if ($overlap >= 14) {
                $score += 80 * $weight;
            } elseif ($overlap === 13) {
                $score += 46 * $weight;
            } elseif ($overlap === 12) {
                $score += 24 * $weight;
            } elseif ($overlap === 11) {
                $score += 8 * $weight;
            }
        }

        return $score;
    }

    protected function positionWeight(int $index): float
    {
        return match (true) {
            $index <= 2 => 1.00,
            $index <= 5 => 0.82,
            $index <= 10 => 0.64,
            $index <= 20 => 0.42,
            default => 0.22,
        };
    }
}