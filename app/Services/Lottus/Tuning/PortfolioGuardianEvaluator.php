<?php

namespace App\Services\Lottus\Tuning;

class PortfolioGuardianEvaluator
{
    public function evaluate(array $resultado): array
    {
        $weights = config('lottus_portfolio_guardian.weights', []);

        $faixas = $resultado['faixas'] ?? [];
        $diagnostico = $resultado['diagnostico'] ?? [];

        $selected11 = (int) ($faixas[11] ?? 0);
        $selected12 = (int) ($faixas[12] ?? 0);
        $selected13 = (int) ($faixas[13] ?? 0);
        $selected14 = (int) ($faixas[14] ?? 0);
        $selected15 = (int) ($faixas[15] ?? 0);

        $lossTotal = 0;
        $lossCount = 0;
        $raw13 = 0;
        $raw14 = 0;
        $raw15 = 0;
        $raw13Preserved = 0;
        $raw14Preserved = 0;
        $raw15Preserved = 0;
        $raw13Lost = 0;
        $raw14Lost = 0;
        $raw15Lost = 0;

        foreach ($diagnostico as $item) {
            $raw = (int) ($item['raw'] ?? 0);
            $selected = (int) ($item['selected'] ?? 0);
            $loss = max(0, (int) ($item['loss'] ?? 0));

            if ($raw >= 13) {
                $raw13++;
            }

            if ($raw >= 14) {
                $raw14++;
            }

            if ($raw >= 15) {
                $raw15++;
            }

            if ($raw >= 13 && $selected >= 13) {
                $raw13Preserved++;
            }

            if ($raw >= 14 && $selected >= 14) {
                $raw14Preserved++;
            }

            if ($raw >= 15 && $selected >= 15) {
                $raw15Preserved++;
            }

            if ($raw >= 13 && $selected < 13) {
                $raw13Lost++;
            }

            if ($raw >= 14 && $selected < 14) {
                $raw14Lost++;
            }

            if ($raw >= 15 && $selected < 15) {
                $raw15Lost++;
            }

            if ($raw >= 12 || $loss > 0) {
                $lossTotal += $loss;
                $lossCount++;
            }
        }

        $averageLoss = $lossCount > 0 ? round($lossTotal / $lossCount, 4) : 0.0;

        $score = 0;

        $score += $selected15 * (int) ($weights['selected_15'] ?? 10000000);
        $score += $selected14 * (int) ($weights['selected_14'] ?? 1500000);
        $score += $selected13 * (int) ($weights['selected_13'] ?? 120000);
        $score += $selected12 * (int) ($weights['selected_12'] ?? 3000);
        $score += $selected11 * (int) ($weights['selected_11'] ?? 150);

        $score += $raw14Preserved * (int) ($weights['raw_14_preserved'] ?? 400000);
        $score += $raw13Preserved * (int) ($weights['raw_13_preserved'] ?? 80000);

        $score += $raw14Lost * (int) ($weights['raw_14_lost'] ?? -800000);
        $score += $raw13Lost * (int) ($weights['raw_13_lost'] ?? -120000);

        $score += $lossTotal * (int) ($weights['loss_penalty'] ?? -25000);
        $score += (int) round($averageLoss * (int) ($weights['average_loss_penalty'] ?? -150000));

        return [
            'score' => $score,
            'selected_11' => $selected11,
            'selected_12' => $selected12,
            'selected_13' => $selected13,
            'selected_14' => $selected14,
            'selected_15' => $selected15,
            'raw_13' => $raw13,
            'raw_14' => $raw14,
            'raw_15' => $raw15,
            'raw_13_preserved' => $raw13Preserved,
            'raw_14_preserved' => $raw14Preserved,
            'raw_15_preserved' => $raw15Preserved,
            'raw_13_lost' => $raw13Lost,
            'raw_14_lost' => $raw14Lost,
            'raw_15_lost' => $raw15Lost,
            'loss_total' => $lossTotal,
            'loss_count' => $lossCount,
            'average_loss' => $averageLoss,
            'melhor_acerto' => (int) ($resultado['melhor_resultado']['acertos'] ?? 0),
            'melhor_concurso' => $resultado['melhor_resultado']['concurso'] ?? null,
        ];
    }

    public function shouldStop(array $metrics): bool
    {
        $conditions = config('lottus_portfolio_guardian.stop_conditions', []);

        $targetAverageLoss = (float) ($conditions['target_average_loss'] ?? 1.0);
        $minimumSelected13 = (int) ($conditions['minimum_selected_13'] ?? 3);
        $minimumSelected14 = (int) ($conditions['minimum_selected_14'] ?? 1);
        $allowStopWithout14 = (bool) ($conditions['allow_stop_without_14'] ?? false);

        if ((float) $metrics['average_loss'] > $targetAverageLoss) {
            return false;
        }

        if ((int) $metrics['selected_13'] < $minimumSelected13) {
            return false;
        }

        if (! $allowStopWithout14 && (int) $metrics['selected_14'] < $minimumSelected14) {
            return false;
        }

        return true;
    }
}