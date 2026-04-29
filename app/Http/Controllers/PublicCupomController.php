<?php

namespace App\Http\Controllers;

use App\Services\CupomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicCupomController extends Controller
{
    public function __construct(
        private readonly CupomService $cupomService
    ) {
    }

    public function validar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:50'],
            'quantidade' => ['nullable', 'integer', 'min:1', 'max:20'],
            'email' => ['nullable', 'email'],
            'produto' => ['nullable', 'string', 'max:50'],
            'quantidade_dezenas' => ['nullable', 'integer', 'min:16', 'max:20'],
        ]);

        $produto = $data['produto'] ?? 'selecao';

        if ($produto === 'fechamento') {
            $quantidadeDezenas = (int) ($data['quantidade_dezenas'] ?? 16);
            $subtotal = (float) config("lottus_fechamento.prices.{$quantidadeDezenas}", 0);

            if ($subtotal <= 0) {
                return response()->json([
                    'valido' => false,
                    'mensagem' => 'Valor do fechamento não configurado.',
                ], 422);
            }
        } else {
            $valorUnitario = (float) env('LOTTUS_GAME_PRICE', 2.00);
            $quantidade = (int) ($data['quantidade'] ?? 1);
            $subtotal = round($valorUnitario * $quantidade, 2);
        }

        $resultado = $this->cupomService->validarCupom(
            $data['codigo'],
            $subtotal,
            $data['email'] ?? null
        );

        if (! $resultado['valido']) {
            return response()->json($resultado, 422);
        }

        return response()->json([
            'valido' => true,
            'mensagem' => $resultado['mensagem'],
            'codigo' => $resultado['cupom']->codigo,
            'descricao' => $resultado['descricao'],
            'subtotal' => $resultado['subtotal'],
            'desconto' => $resultado['desconto'],
            'valor_final' => $resultado['valor_final'],
        ]);
    }
}