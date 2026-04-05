<?php

namespace App\Http\Controllers;

use App\Models\LottusPedido;
use App\Services\MercadoPagoCheckoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MercadoPagoWebhookController extends Controller
{
    public function __construct(
        private readonly MercadoPagoCheckoutService $mercadoPagoCheckoutService
    ) {
    }

    public function handle(Request $request)
    {
        Log::info('Webhook Mercado Pago recebido', [
            'payload' => $request->all(),
        ]);

        $type = $request->input('type') ?? $request->input('topic');
        $paymentId = $request->input('data.id') ?? $request->input('id');

        if ($type !== 'payment' || ! $paymentId) {
            Log::info('Webhook ignorado: tipo diferente de payment ou sem paymentId', [
                'type' => $type,
                'payment_id' => $paymentId,
            ]);

            return response()->json(['ok' => true]);
        }

        try {
            $payment = $this->mercadoPagoCheckoutService->buscarPagamentoPorId((string) $paymentId);

            $externalReference = $payment->external_reference ?? null;
            $status = $payment->status ?? null;

            Log::info('Pagamento consultado no Mercado Pago', [
                'payment_id' => $payment->id ?? null,
                'external_reference' => $externalReference,
                'status' => $status,
            ]);

            if (! $externalReference) {
                Log::warning('Pagamento sem external_reference', [
                    'payment_id' => $payment->id ?? null,
                ]);

                return response()->json(['ok' => true]);
            }

            $pedido = LottusPedido::where('external_reference', $externalReference)->first();

            if (! $pedido) {
                Log::warning('Pedido não encontrado para external_reference', [
                    'external_reference' => $externalReference,
                ]);

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

                Log::info('Pedido marcado como pago', [
                    'pedido_id' => $pedido->id,
                    'pedido_token' => $pedido->token,
                    'payment_id' => $payment->id ?? null,
                ]);
            }

            if (in_array($status, ['cancelled', 'rejected'], true)) {
                $pedido->update([
                    'status' => 'cancelado',
                ]);

                Log::info('Pedido marcado como cancelado', [
                    'pedido_id' => $pedido->id,
                    'pedido_token' => $pedido->token,
                    'payment_status' => $status,
                ]);
            }

            if ($status === 'expired') {
                $pedido->update([
                    'status' => 'expirado',
                ]);

                Log::info('Pedido marcado como expirado', [
                    'pedido_id' => $pedido->id,
                    'pedido_token' => $pedido->token,
                ]);
            }

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            Log::error('Erro ao processar webhook do Mercado Pago', [
                'message' => $e->getMessage(),
                'payload' => $request->all(),
            ]);

            return response()->json(['ok' => false], 500);
        }
    }
}