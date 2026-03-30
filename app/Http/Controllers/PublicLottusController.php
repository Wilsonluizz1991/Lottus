<?php

namespace App\Http\Controllers;

use App\Models\LottusPedido;
use App\Models\LotofacilConcurso;
use App\Services\LottusGeradorService;
use App\Services\MercadoPagoCheckoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

class PublicLottusController extends Controller
{
    public function __construct(
        private readonly LottusGeradorService $geradorService,
        private readonly MercadoPagoCheckoutService $mercadoPagoCheckoutService,
    ) {
    }

    public function home()
    {
        $ultimoConcurso = LotofacilConcurso::orderByDesc('concurso')->first();
        $valorUnitario = (float) env('LOTTUS_GAME_PRICE', 2.00);

        return view('public.home', [
            'ultimoConcurso' => $ultimoConcurso,
            'preco' => number_format($valorUnitario, 2, ',', '.'),
            'valorUnitario' => $valorUnitario,
        ]);
    }

    public function gerarJogo(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'quantidade' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        try {
            $concursoBase = $this->resolverConcursoBaseParaVenda();
        } catch (RuntimeException $e) {
            return redirect()->route('home')->with('error', $e->getMessage());
        }

        $quantidade = (int) $request->quantidade;
        $valorUnitario = (float) env('LOTTUS_GAME_PRICE', 2.00);
        $valorTotal = $valorUnitario * $quantidade;

        $jogos = [];
        $analises = [];

        for ($i = 0; $i < $quantidade; $i++) {
            $resultado = $this->geradorService->gerar($concursoBase);
            $jogos[] = $resultado['dezenas'];
            $analises[] = $resultado['analise'];
        }

        $pedido = LottusPedido::create([
            'token' => (string) Str::uuid(),
            'email' => $request->email,
            'quantidade' => $quantidade,
            'concurso_base_id' => $concursoBase->id,
            'valor' => $valorTotal,
            'jogo' => $jogos,
            'analise' => $analises,
            'status' => 'aguardando_pagamento',
            'gateway' => 'mercadopago',
            'external_reference' => 'lottus_' . Str::uuid(),
        ]);

        return redirect()
            ->route('pedido.show', $pedido->token)
            ->with('abrir_modal_pagamento', true);
    }

    public function showPedido(string $token)
    {
        $pedido = LottusPedido::with('concursoBase')
            ->where('token', $token)
            ->firstOrFail();

        return view('public.pedido', [
            'pedido' => $pedido,
        ]);
    }

    private function resolverConcursoBaseParaVenda(): LotofacilConcurso
    {
        $ultimoConcurso = LotofacilConcurso::orderByDesc('concurso')->first();

        if (! $ultimoConcurso) {
            throw new RuntimeException('Nenhum concurso foi encontrado na base.');
        }

        return $ultimoConcurso;
    }
}