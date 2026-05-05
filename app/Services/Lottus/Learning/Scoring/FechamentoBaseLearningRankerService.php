<?php

namespace App\Services\Lottus\Learning\Scoring;

use App\Models\LotofacilConcurso;
use Illuminate\Support\Collection;

class FechamentoBaseLearningRankerService
{
    public function __construct(
        protected FechamentoLearningScoringService $learningScoringService
    ) {
    }

    public function rank(
        array $bases,
        int $quantidadeDezenas,
        Collection $historico,
        LotofacilConcurso $concursoBase,
        int $limit = 12
    ): array {
        $bases = $this->normalizeBases($bases, $quantidadeDezenas);

        if (empty($bases)) {
            return [];
        }

        $ranked = $this->learningScoringService->rankBases(
            bases: $bases,
            quantidadeDezenas: $quantidadeDezenas,
            historico: $historico,
            concursoBase: $concursoBase
        );

        $ranked = array_values(array_filter(
            $ranked,
            fn (array $item): bool => count($item['base'] ?? []) === $quantidadeDezenas
        ));

        usort($ranked, function (array $a, array $b): int {
            if (($a['final_score'] ?? 0.0) === ($b['final_score'] ?? 0.0)) {
                return implode('-', $a['base'] ?? []) <=> implode('-', $b['base'] ?? []);
            }

            return ($b['final_score'] ?? 0.0) <=> ($a['final_score'] ?? 0.0);
        });

        return array_slice($ranked, 0, max(1, $limit));
    }

    public function bestBase(
        array $bases,
        int $quantidadeDezenas,
        Collection $historico,
        LotofacilConcurso $concursoBase
    ): array {
        $ranked = $this->rank(
            bases: $bases,
            quantidadeDezenas: $quantidadeDezenas,
            historico: $historico,
            concursoBase: $concursoBase,
            limit: 1
        );

        return $ranked[0]['base'] ?? [];
    }

    public function onlyBases(
        array $bases,
        int $quantidadeDezenas,
        Collection $historico,
        LotofacilConcurso $concursoBase,
        int $limit = 12
    ): array {
        $ranked = $this->rank(
            bases: $bases,
            quantidadeDezenas: $quantidadeDezenas,
            historico: $historico,
            concursoBase: $concursoBase,
            limit: $limit
        );

        return array_map(
            fn (array $item): array => $item['base'],
            $ranked
        );
    }

    protected function normalizeBases(array $bases, int $quantidadeDezenas): array
    {
        $normalized = [];
        $seen = [];

        foreach ($bases as $base) {
            $base = $this->normalizeNumbers($base);

            if (count($base) !== $quantidadeDezenas) {
                continue;
            }

            $key = implode('-', $base);

            if (isset($seen[$key])) {
                continue;
            }

            $normalized[] = $base;
            $seen[$key] = true;
        }

        return $normalized;
    }

    protected function normalizeNumbers(array $numbers): array
    {
        $numbers = array_values(array_unique(array_map('intval', $numbers)));
        $numbers = array_values(array_filter($numbers, fn (int $number) => $number >= 1 && $number <= 25));
        sort($numbers);

        return $numbers;
    }
}