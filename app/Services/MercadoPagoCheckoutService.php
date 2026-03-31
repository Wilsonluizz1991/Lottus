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
        $token = config('services.mercado_pago.access_token');

        if (!$token) {
            $token = env('MERCADO_PAGO_ACCESS_TOKEN');
        }

        if (!$token) {
            throw new \Exception('MERCADO_PAGO_ACCESS_TOKEN não configurado');
        }

        MercadoPagoConfig::setAccessToken($token);
    }

    public function criarCheckout(LottusPedido $pedido): array
    {
        $client = new PreferenceClient();

        // 🔥 PAYLOAD LIMPO (SEM URL LOCAL)
        $request = [
            'items' => [
                [
                    'id' => (string) $pedido->id,
                    'title' => 'Jogo Lottus',
                    'currency_id' => 'BRL',
                    'quantity' => 1,
                    'unit_price' => (float) $pedido->valor,
                ]
            ],
            'payer' => [
                'email' => $pedido->email,
            ],
            'external_reference' => $pedido->external_reference,
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
        ];
    }

    public function buscarPagamentoPorId(string $paymentId): mixed
    {
        $client = new PaymentClient();
        return $client->get($paymentId);
    }
}