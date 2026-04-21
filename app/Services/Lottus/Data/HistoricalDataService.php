<?php

namespace App\Services\Lottus\Data;

use App\Models\LotofacilConcurso;
use Illuminate\Support\Collection;

class HistoricalDataService
{
    public function getUntilContest(int $contestNumber): Collection
    {
        return LotofacilConcurso::query()
            ->where('concurso', '<=', $contestNumber)
            ->orderBy('concurso')
            ->get()
            ->map(function (LotofacilConcurso $concurso) {
                return [
                    'id' => $concurso->id,
                    'concurso' => (int) $concurso->concurso,
                    'data_sorteio' => $concurso->data_sorteio,
                    'dezenas' => $this->extractNumbers($concurso),
                ];
            })
            ->values();
    }

    protected function extractNumbers(LotofacilConcurso $concurso): array
    {
        $numbers = [];

        for ($i = 1; $i <= 15; $i++) {
            $numbers[] = (int) $concurso->{'bola' . $i};
        }

        sort($numbers);

        return $numbers;
    }
}