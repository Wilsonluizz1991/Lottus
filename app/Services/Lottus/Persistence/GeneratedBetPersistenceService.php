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
                'pares' => $game['pares'] ?? 0,
                'impares' => $game['impares'] ?? 0,
                'soma' => $game['soma'] ?? 0,
                'repetidas_ultimo_concurso' => $game['repetidas_ultimo_concurso'] ?? 0,
                'quentes' => $game['quentes'] ?? 0,
                'atrasadas' => $game['atrasadas'] ?? 0,
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