<?php

namespace App\Services\Lottus\Learning\Scoring;

use App\Models\LotofacilConcurso;
use Illuminate\Support\Collection;

class FechamentoBaseDecisionService
{
    public function __construct(
        protected FechamentoBaseLearningRankerService $rankerService
    ) {
    }

    public function decide(
        array $bases,
        int $quantidadeDezenas,
        Collection $historico,
        LotofacilConcurso $concursoBase,
        int $limit = 12
    ): array {
        $ranked = $this->rankerService->rank(
            bases: $bases,
            quantidadeDezenas: $quantidadeDezenas,
            historico: $historico,
            concursoBase: $concursoBase,
            limit: $limit
        );

        if (empty($ranked)) {
            return [
                'bases' => [],
                'ranked' => [],
                'winner' => null,
            ];
        }

        return [
            'bases' => array_map(
                fn (array $item): array => $item['base'],
                $ranked
            ),
            'ranked' => $ranked,
            'winner' => $ranked[0] ?? null,
        ];
    }

    public function winner(
        array $bases,
        int $quantidadeDezenas,
        Collection $historico,
        LotofacilConcurso $concursoBase
    ): array {
        $decision = $this->decide(
            bases: $bases,
            quantidadeDezenas: $quantidadeDezenas,
            historico: $historico,
            concursoBase: $concursoBase,
            limit: 1
        );

        return $decision['winner']['base'] ?? [];
    }
}