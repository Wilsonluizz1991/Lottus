<?php

namespace App\Http\Controllers;

use App\Models\LottusPedido;
use App\Services\MercadoPagoCheckoutService;

class MercadoPagoCheckoutController extends Controller
{
    public function __construct(
        private readonly MercadoPagoCheckoutService $checkoutService
    ) {}

    public function pagar(string $token)
    {
        $pedido = LottusPedido::where('token', $token)->firstOrFail();

        if ($pedido->isPaid()) {
            return redirect()
                ->route('pedido.show', $pedido->token)
                ->with('info', 'Este pedido já está pago.');
        }

        $checkout = $this->checkoutService->criarCheckout($pedido);

        if (empty($checkout['init_point'])) {
            return redirect()
                ->route('pedido.show', $pedido->token)
                ->with('error', 'Erro ao iniciar pagamento.');
        }

        return redirect()->away($checkout['init_point']);
    }
}