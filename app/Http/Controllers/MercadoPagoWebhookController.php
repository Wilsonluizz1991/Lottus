<?php

namespace App\Http\Controllers;

use App\Models\LottusPedido;
use App\Services\MercadoPagoCheckoutService;
use Illuminate\Http\Request;

class MercadoPagoWebhookController extends Controller
{
    public function __construct(
        private readonly MercadoPagoCheckoutService $mercadoPagoCheckoutService
    ) {
    }

    public function handle(Request $request)
    {
        $type = $request->input('type') ?? $request->input('topic');
        $paymentId = $request->input('data.id') ?? $request->input('id');

        if ($type !== 'payment' || ! $paymentId) {
            return response()->json(['ok' => true]);
        }

        $payment = $this->mercadoPagoCheckoutService->buscarPagamentoPorId((string) $paymentId);

        $externalReference = $payment->external_reference ?? null;
        $status = $payment->status ?? null;

        if (! $externalReference) {
            return response()->json(['ok' => true]);
        }

        $pedido = LottusPedido::where('external_reference', $externalReference)->first();

        if (! $pedido) {
            return response()->json(['ok' => true]);
        }

        $pedido->update([
            'payment_id' => (string) ($payment->id ?? ''),
            'payment_status' => $status,
        ]);

        if ($status === 'approved' && $pedido->status !== 'pago') {
            $pedido->update([
                'status' => 'pago',
                'paid_at' => now(),
            ]);

            // depois você pode acoplar envio de e-mail aqui
            // Mail::to($pedido->email)->queue(new JogoLiberadoMail($pedido));
        }

        if (in_array($status, ['cancelled', 'rejected'], true)) {
            $pedido->update([
                'status' => 'cancelado',
            ]);
        }

        if ($status === 'expired') {
            $pedido->update([
                'status' => 'expirado',
            ]);
        }

        return response()->json(['ok' => true]);
    }
}