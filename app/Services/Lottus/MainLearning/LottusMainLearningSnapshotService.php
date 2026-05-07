<?php

namespace App\Services\Lottus\MainLearning;

use App\Models\LottusMainLearningSnapshot;
use Illuminate\Support\Facades\Schema;

class LottusMainLearningSnapshotService
{
    public function contextForTargetContest(int $targetConcurso): array
    {
        if (! (bool) config('lottus_main_learning.enabled', true)) {
            return [];
        }

        if (! Schema::hasTable('lottus_main_learning_snapshots')) {
            return [];
        }

        $statuses = (bool) config('lottus_main_learning.use_promoted_only', true)
            ? [LottusMainLearningSnapshot::STATUS_PROMOTED]
            : [
                LottusMainLearningSnapshot::STATUS_PROMOTED,
                LottusMainLearningSnapshot::STATUS_PENDING,
            ];

        $snapshot = LottusMainLearningSnapshot::query()
            ->whereIn('status', $statuses)
            ->where('target_concurso', '<=', $targetConcurso)
            ->orderByDesc('target_concurso')
            ->orderByDesc('version')
            ->first();

        if (! $snapshot) {
            return [];
        }

        $payload = $snapshot->payload_json ?? [];

        if (! is_array($payload)) {
            return [];
        }

        $payload['_snapshot'] = [
            'id' => $snapshot->id,
            'status' => $snapshot->status,
            'version' => $snapshot->version,
            'concurso_base' => $snapshot->concurso_base,
            'target_concurso' => $snapshot->target_concurso,
            'confidence' => (float) $snapshot->confidence,
        ];

        return $payload;
    }

    public function contextForConcursoBase(int $concursoBase): array
    {
        return $this->contextForTargetContest($concursoBase + 1);
    }

    public function decorateCandidates(array $candidates, array $context): array
    {
        if (empty($context)) {
            return $candidates;
        }

        foreach ($candidates as &$candidate) {
            $candidate['main_learning'] = $context;
        }

        unset($candidate);

        return $candidates;
    }
}
