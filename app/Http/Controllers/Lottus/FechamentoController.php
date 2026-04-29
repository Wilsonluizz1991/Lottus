<?php

namespace App\Http\Controllers\Lottus;

use App\Http\Controllers\Controller;
use App\Models\LotofacilConcurso;
use App\Services\Lottus\Fechamento\FechamentoEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FechamentoController extends Controller
{
    public function __construct(
        protected FechamentoEngine $engine
    ) {
    }

    public function gerar(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'quantidade_dezenas' => 'required|integer|min:16|max:20',
            'cupom' => 'nullable|string|max:255',
        ]);

        try {
            $ultimoConcurso = LotofacilConcurso::query()
                ->orderByDesc('concurso')
                ->first();

            if (! $ultimoConcurso) {
                return back()->withErrors([
                    'erro' => 'Nenhum concurso base encontrado para gerar o fechamento.',
                ]);
            }

            DB::beginTransaction();

            $resultado = $this->engine->generate([
                'email' => $validated['email'],
                'quantidade_dezenas' => (int) $validated['quantidade_dezenas'],
                'concurso_base' => $ultimoConcurso,
                'cupom' => $validated['cupom'] ?? null,
            ]);

            if (
                empty($resultado) ||
                empty($resultado['pedido'])
            ) {
                throw new \Exception('O motor de fechamento não retornou um pedido válido.');
            }

            DB::commit();

            return redirect()->route('pedido.show', $resultado['pedido']->token);

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Erro ao gerar Fechamento Inteligente Lottus', [
                'erro' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'email' => $validated['email'] ?? null,
                'quantidade_dezenas' => $validated['quantidade_dezenas'] ?? null,
            ]);

            return back()
                ->withInput()
                ->withErrors([
                    'erro' => 'Falha ao gerar o Fechamento Inteligente. ' . $e->getMessage(),
                ]);
        }
    }
}