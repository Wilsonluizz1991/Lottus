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
            'quantidade' => ['required', 'integer', 'min:1', 'max:20'],
            'email' => ['nullable', 'email'],
        ]);

        $valorUnitario = (float) env('LOTTUS_GAME_PRICE', 2.00);
        $subtotal = round($valorUnitario * (int) $data['quantidade'], 2);

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