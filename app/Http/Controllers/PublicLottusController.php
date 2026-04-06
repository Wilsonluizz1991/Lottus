<?php

namespace App\Http\Controllers;

use App\Models\LottusPedido;
use App\Models\LotofacilConcurso;
use App\Services\CupomService;
use App\Services\LottusGeradorService;
use App\Services\MercadoPagoCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class PublicLottusController extends Controller
{
    public function __construct(
        private readonly LottusGeradorService $geradorService,
        private readonly CupomService $cupomService,
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
            'cupom' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $concursoBase = $this->resolverConcursoBaseParaVenda();
        } catch (RuntimeException $e) {
            return redirect()->route('home')->with('error', $e->getMessage());
        }

        $quantidade = (int) $request->quantidade;
        $valorUnitario = (float) env('LOTTUS_GAME_PRICE', 2.00);
        $subtotal = round($valorUnitario * $quantidade, 2);

        $cupom = null;
        $cupomCodigo = null;
        $desconto = 0.00;
        $valorFinal = $subtotal;

        if ($request->filled('cupom')) {
            $resultadoCupom = $this->cupomService->validarCupom(
                $request->cupom,
                $subtotal,
                $request->email
            );

            if (! $resultadoCupom['valido']) {
                return redirect()
                    ->route('home')
                    ->withInput()
                    ->with('error', $resultadoCupom['mensagem']);
            }

            $cupom = $resultadoCupom['cupom'];
            $cupomCodigo = $cupom->codigo;
            $desconto = $resultadoCupom['desconto'];
            $valorFinal = $resultadoCupom['valor_final'];
        }

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
            'cupom_id' => $cupom?->id,
            'cupom_codigo' => $cupomCodigo,
            'subtotal' => $subtotal,
            'desconto' => $desconto,
            'valor_original' => $subtotal,
            'valor' => $valorFinal,
            'jogo' => $jogos,
            'analise' => $analises,
            'status' => 'aguardando_pagamento',
            'gateway' => 'mercado_pago',
            'external_reference' => 'lottus_' . Str::uuid(),
        ]);

        if ($cupom) {
            $this->cupomService->registrarUso($cupom);
        }

        if ($valorFinal <= 0) {
            $pedido->update([
                'status' => 'pago',
                'payment_status' => 'approved',
                'gateway' => 'cupom',
                'paid_at' => now(),
            ]);

            return redirect()
                ->route('pedido.show', $pedido->token)
                ->with('success', 'Jogo liberado gratuitamente via cupom.');
        }

        return redirect()
            ->route('pedido.show', $pedido->token)
            ->with('success', 'Pedido gerado com sucesso.');
    }

    public function showPedido(string $token, MercadoPagoCheckoutService $mercadoPagoCheckoutService)
    {
        $pedido = LottusPedido::with('concursoBase')
            ->where('token', $token)
            ->firstOrFail();

        $checkoutUrl = null;

        if (! $pedido->isPaid() && (float) $pedido->valor > 0) {
            try {
                $checkout = $mercadoPagoCheckoutService->criarCheckout($pedido);
                $checkoutUrl = $checkout['init_point'] ?? null;
            } catch (\Throwable $e) {
                Log::error('Erro ao gerar checkout do Mercado Pago na exibição do pedido', [
                    'pedido_id' => $pedido->id,
                    'pedido_token' => $pedido->token,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return view('public.pedido', [
            'pedido' => $pedido,
            'checkoutUrl' => $checkoutUrl,
        ]);
    }

    public function statusPedido(string $token): JsonResponse
    {
        $pedido = LottusPedido::where('token', $token)->firstOrFail();

        return response()->json([
            'status' => $pedido->status,
            'payment_status' => $pedido->payment_status,
            'is_paid' => $pedido->isPaid(),
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