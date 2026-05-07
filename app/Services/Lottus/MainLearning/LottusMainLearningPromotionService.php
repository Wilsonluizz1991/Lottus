<?php

namespace App\Services\Lottus\MainLearning;

use App\Models\LottusMainLearningSnapshot;

class LottusMainLearningPromotionService
{
    public function decide(array $baselineMetrics, array $learnedMetrics, array $delta): array
    {
        $confidence = $this->confidence($baselineMetrics, $learnedMetrics, $delta);
        $minimumConfidence = (float) config('lottus_main_learning.promotion.minimum_confidence', 0.62);
        $maxLoss = (int) config('lottus_main_learning.promotion.max_loss14', 0);
        $lossDelta = (float) ($delta['loss14_15'] ?? 0.0);

        $improved = app(LottusMainBehaviorAnalysisService::class)->isImprovement($delta);

        if ($lossDelta > $maxLoss) {
            return [
                'status' => LottusMainLearningSnapshot::STATUS_REJECTED,
                'decision' => 'rejected',
                'confidence' => $confidence,
                'reason' => 'loss_14_15_piorou',
            ];
        }

        if ($improved && $confidence >= $minimumConfidence) {
            return [
                'status' => LottusMainLearningSnapshot::STATUS_PROMOTED,
                'decision' => 'promoted',
                'confidence' => $confidence,
                'reason' => 'ganho_real_em_metricas_14_15_near15_ou_pacote_curto',
            ];
        }

        if ($improved) {
            return [
                'status' => LottusMainLearningSnapshot::STATUS_PENDING,
                'decision' => 'pending',
                'confidence' => $confidence,
                'reason' => 'ganho_detectado_mas_confianca_insuficiente',
            ];
        }

        if ((float) ($delta['elite_score'] ?? 0.0) < 0) {
            return [
                'status' => LottusMainLearningSnapshot::STATUS_REJECTED,
                'decision' => 'rejected',
                'confidence' => $confidence,
                'reason' => 'elite_score_piorou',
            ];
        }

        return [
            'status' => LottusMainLearningSnapshot::STATUS_PENDING,
            'decision' => 'pending',
            'confidence' => $confidence,
            'reason' => 'empate_estatistico_shadow',
        ];
    }

    protected function confidence(array $baselineMetrics, array $learnedMetrics, array $delta): float
    {
        $concursos = max(1, (int) ($learnedMetrics['concursos'] ?? $baselineMetrics['concursos'] ?? 1));
        $sampleFactor = min(1.0, $concursos / max(1, (int) config('lottus_main_learning.min_sample_size', 30)));
        $eliteDelta = max(0.0, (float) ($delta['elite_score'] ?? 0.0));
        $nearDelta = max(0.0, (float) ($delta['near15'] ?? 0.0));
        $rawDelta = max(0.0, (float) ($delta['raw14'] ?? 0.0) + (float) ($delta['raw15'] ?? 0.0));
        $selectedDelta = max(0.0, (float) ($delta['selected14'] ?? 0.0) + (float) ($delta['selected15'] ?? 0.0));
        $lossPenalty = max(0.0, (float) ($delta['loss14_15'] ?? 0.0)) * 0.24;

        $signal = min(1.0, ($eliteDelta / 2200.0) + ($nearDelta * 0.06) + ($rawDelta * 0.18) + ($selectedDelta * 0.28));

        return round(max(0.0, min(1.0, ($sampleFactor * 0.42) + ($signal * 0.58) - $lossPenalty)), 6);
    }
}
