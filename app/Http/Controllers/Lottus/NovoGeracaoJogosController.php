<?php

namespace App\Http\Controllers\Lottus;

use App\Http\Controllers\Controller;
use App\Models\LotofacilConcurso;
use App\Services\Lottus\Engine\LotofacilEngine;
use App\Services\Lottus\Integration\PedidoIntegrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NovoGeracaoJogosController extends Controller
{
    public function gerar(
        Request $request,
        LotofacilEngine $engine,
        PedidoIntegrationService $pedidoIntegrationService
    ) {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'quantidade' => ['required', 'integer', 'min:1', 'max:10'],
            'cupom' => ['nullable', 'string', 'max:255'],
        ]);

        $ultimoConcurso = LotofacilConcurso::query()
            ->orderByDesc('concurso')
            ->first();

        if (! $ultimoConcurso) {
            return back()->with('error', 'Nenhum concurso encontrado.');
        }

        try {
            DB::beginTransaction();

            $resultado = $engine->generate([
                'email' => $validated['email'],
                'quantidade' => (int) $validated['quantidade'],
                'cupom' => $validated['cupom'] ?? null,
                'concurso_base' => $ultimoConcurso,
            ]);

            $pedido = $pedidoIntegrationService->criarPedidoAPartirDoLote(
                $resultado['token_lote'],
                $validated['email'],
                $validated['cupom'] ?? null
            );

            DB::commit();

            return redirect()->route('pedido.show', $pedido->token);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Erro no novo motor Lottus', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Erro ao gerar jogos.');
        }
    }
}