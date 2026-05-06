<?php

namespace App\Services\Lottus\Learning\Contracts;

use App\Models\LotofacilConcurso;

interface LearningStrategyInterface
{
    public function engine(): string;

    public function strategy(): string;

    public function learn(
        LotofacilConcurso $concursoAtual
    ): array;
}
