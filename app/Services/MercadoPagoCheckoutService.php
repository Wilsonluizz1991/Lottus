<?php

namespace App\Services;

use App\Models\LottusPedido;
use MercadoPago\Client\Common\RequestOptions;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\MercadoPagoConfig;
use Illuminate\Support\Str;

class MercadoPagoCheckoutService
{
    public function __construct()
    {
        MercadoPagoConfig::setAccessToken(config('services.mercadopago.access_token'));
    }

    public function criarCheckout(LottusPedido $pedido): array
    {
        $client = new PreferenceClient();

        $request = [
            'items' => [
                [
                    'id' => (string) $pedido->id,
                    'title' => 'Jogo Lottus',
                    'description' => 'Jogo gerado pelo Lottus com análise estatística',
                    'currency_id' => 'BRL',
                    'quantity' => 1,
                    'unit_price' => (float) $pedido->valor,
                ]
            ],
            'payer' => [
                'email' => $pedido->email,
            ],
            'external_reference' => $pedido->external_reference,
            'notification_url' => route('pagamentos.mercadopago.webhook'),
            'back_urls' => [
                'success' => route('pedido.show', $pedido->token),
                'failure' => route('pedido.show', $pedido->token),
                'pending' => route('pedido.show', $pedido->token),
            ],
            'auto_return' => 'approved',
            'expires' => true,
            'date_of_expiration' => now()->addHours(24)->toIso8601String(),
            'payment_methods' => [
                'installments' => 12,
                'default_installments' => 1,
            ],
        ];

        $requestOptions = new RequestOptions();
        $requestOptions->setCustomHeaders([
            'X-Idempotency-Key: ' . Str::uuid()->toString(),
        ]);

        $preference = $client->create($request, $requestOptions);

        return [
            'preference_id' => $preference->id ?? null,
            'init_point' => $preference->init_point ?? null,
            'sandbox_init_point' => $preference->sandbox_init_point ?? null,
            'expires_at' => now()->addHours(24),
        ];
    }

    public function buscarPagamentoPorId(string $paymentId): mixed
    {
        $client = new PaymentClient();

        return $client->get($paymentId);
    }
}