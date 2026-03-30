<?php

namespace App\Http\Controllers;

use App\Models\LottusPedido;
use App\Models\LotofacilConcurso;
use App\Services\LottusGeradorService;
use App\Services\MercadoPagoCheckoutService;
use Carbon\Carbon;
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

        return view('public.home', [
            'ultimoConcurso' => $ultimoConcurso,
            'preco' => number_format((float) config('app.lottus_game_price', env('LOTTUS_GAME_PRICE', 2.00)), 2, ',', '.'),
        ]);
    }

    public function gerarJogo(Request $request)
{
    $request->validate([
        'email' => ['required', 'email'],
    ]);

    try {
        $concursoBase = $this->resolverConcursoBaseParaVenda();
    } catch (\RuntimeException $e) {
        return redirect()->route('home')->with('error', $e->getMessage());
    }

    $resultado = $this->geradorService->gerar($concursoBase);

    $pedido = LottusPedido::create([
        'token' => (string) \Illuminate\Support\Str::uuid(),
        'email' => $request->email,
        'concurso_base_id' => $concursoBase->id,
        'valor' => (float) env('LOTTUS_GAME_PRICE', 2.00),
        'jogo' => $resultado['dezenas'],
        'analise' => $resultado['analise'],
        'status' => 'aguardando_pagamento',
        'gateway' => 'mercadopago',
        'external_reference' => 'lottus_' . \Illuminate\Support\Str::uuid(),
    ]);

    // $checkout = $this->mercadoPagoCheckoutService->criarCheckout($pedido);

    // $pedido->update([
    //     'gateway_preference_id' => $checkout['preference_id'],
    //     'payment_url' => $checkout['init_point'],
    //     'sandbox_payment_url' => $checkout['sandbox_init_point'],
    //     'expires_at' => $checkout['expires_at'],
    // ]);

    return redirect()
        ->route('pedido.show', $pedido->token)
        ->with('abrir_modal_pagamento', true);
}

    public function showPedido(string $token)
    {
        $pedido = LottusPedido::with('concursoBase')->where('token', $token)->firstOrFail();

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

        $agora = now()->timezone(config('app.timezone', 'America/Sao_Paulo'));

        $dataUltimoConcurso = $ultimoConcurso->data_sorteio instanceof Carbon
            ? $ultimoConcurso->data_sorteio->copy()->startOfDay()
            : Carbon::parse($ultimoConcurso->data_sorteio)->startOfDay();

        $proximaDataSorteio = $this->getProximaDataDeSorteio($dataUltimoConcurso);
        $limiteColeta = $proximaDataSorteio->copy()->setTime(21, 0, 0);

        // Se já passou do horário em que o próximo concurso deveria existir,
        // o público não deve comprar jogo até a base ser atualizada.
        if ($agora->greaterThanOrEqualTo($limiteColeta)) {
            throw new RuntimeException(
                'Há um concurso pendente de atualização na base. Atualize o último sorteio antes de vender novos jogos.'
            );
        }

        return $ultimoConcurso;
    }

    private function getProximaDataDeSorteio(Carbon $data): Carbon
    {
        $proxima = $data->copy()->addDay();

        while ($proxima->isSunday()) {
            $proxima->addDay();
        }

        return $proxima->startOfDay();
    }
}