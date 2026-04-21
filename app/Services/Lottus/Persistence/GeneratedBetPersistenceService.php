<?php

namespace App\Services\Lottus\Persistence;

use App\Models\LotofacilAposta;
use App\Models\LotofacilConcurso;
use Illuminate\Support\Str;

class GeneratedBetPersistenceService
{
    public function store(string $email, LotofacilConcurso $concursoBase, array $rankedGames): array
    {
        $tokenLote = (string) Str::uuid();

        foreach ($rankedGames as $ranking => $game) {
            LotofacilAposta::query()->create([
                'email' => $email,
                'token_lote' => $tokenLote,
                'concurso_base_id' => $concursoBase->id,
                'data_esperada_sorteio' => $concursoBase->data_sorteio,
                'dezenas' => $game['dezenas'],
                'score' => $game['score'],
                'pares' => $game['pares'],
                'impares' => $game['impares'],
                'soma' => $game['soma'],
                'repetidas_ultimo_concurso' => $game['repetidas_ultimo_concurso'],
                'quentes' => $game['quentes'],
                'atrasadas' => $game['atrasadas'],
                'analise' => array_merge($game['analise'] ?? [], [
                    'token_lote' => $tokenLote,
                    'engine_version' => 'v2',
                    'ranking' => $ranking + 1,
                    'concurso_base' => $concursoBase->concurso,
                ]),
            ]);
        }

        return [
            'token_lote' => $tokenLote,
            'email' => $email,
            'quantidade' => count($rankedGames),
        ];
    }
}