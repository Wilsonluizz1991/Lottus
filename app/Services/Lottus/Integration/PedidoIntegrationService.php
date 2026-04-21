<?php

namespace App\Services\Lottus\Integration;

use App\Models\LotofacilAposta;
use App\Models\LottusPedido;
use Illuminate\Support\Str;

class PedidoIntegrationService
{
    public function criarPedidoAPartirDoLote(
        string $tokenLote,
        string $email,
        ?string $cupom = null
    ): LottusPedido {
        $apostas = LotofacilAposta::query()
            ->where('token_lote', $tokenLote)
            ->orderByDesc('score')
            ->get();

        if ($apostas->isEmpty()) {
            throw new \Exception('Nenhuma aposta encontrada para o lote informado.');
        }

        $jogos = $apostas
            ->pluck('dezenas')
            ->values()
            ->toArray();

        $quantidade = count($jogos);
        $valorUnitario = (float) config('lottus.valor_jogo', 2.00);
        $subtotal = round($quantidade * $valorUnitario, 2);
        $desconto = 0.00;
        $valorFinal = $subtotal;
        $cupomCodigo = null;
        $cupomId = null;

        if (!empty($cupom)) {
            $cupomCodigo = $cupom;
        }

        return LottusPedido::query()->create([
            'token' => (string) Str::uuid(),
            'email' => $email,
            'quantidade' => $quantidade,
            'concurso_base_id' => $apostas->first()->concurso_base_id,
            'valor' => $valorFinal,
            'jogo' => $jogos,
            'analise' => [
                'token_lote' => $tokenLote,
                'origem' => 'novo_motor',
                'quantidade_apostas' => $quantidade,
                'scores' => $apostas->pluck('score')->values()->toArray(),
            ],
            'status' => 'aguardando_pagamento',
            'gateway' => 'mercadopago',
            'external_reference' => (string) Str::uuid(),
            'expires_at' => now()->addMinutes(30),
            'cupom_id' => $cupomId,
            'cupom_codigo' => $cupomCodigo,
            'subtotal' => $subtotal,
            'desconto' => $desconto,
            'valor_original' => $subtotal,
        ]);
    }
}