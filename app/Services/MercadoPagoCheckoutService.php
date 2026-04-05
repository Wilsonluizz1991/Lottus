<?php

namespace App\Services;

use App\Models\LottusPedido;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MercadoPago\Client\Common\RequestOptions;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\Exceptions\MPException;
use MercadoPago\MercadoPagoConfig;

class MercadoPagoCheckoutService
{
    public function __construct()
    {
        $token = config('services.mercado_pago.access_token');

        if (! $token) {
            $token = env('MERCADO_PAGO_ACCESS_TOKEN');
        }

        if (! $token) {
            throw new \Exception('MERCADO_PAGO_ACCESS_TOKEN não configurado.');
        }

        MercadoPagoConfig::setAccessToken($token);
    }

    public function criarCheckout(LottusPedido $pedido): array
    {
        try {
            $client = new PreferenceClient();

            $request = [
                'items' => [
                    [
                        'id' => (string) $pedido->id,
                        'title' => $pedido->quantidade === 1
                            ? '1 jogo Lottus'
                            : "{$pedido->quantidade} jogos Lottus",
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

            $appUrl = rtrim((string) config('services.mercado_pago.app_url'), '/');

            if (! empty($appUrl) && Str::startsWith($appUrl, 'https://')) {
                $request['back_urls'] = [
                    'success' => $appUrl . '/pagamentos/mercado-pago/success',
                    'failure' => $appUrl . '/pagamentos/mercado-pago/failure',
                    'pending' => $appUrl . '/pagamentos/mercado-pago/pending',
                ];

                $request['auto_return'] = 'approved';
            }

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
        } catch (MPApiException $e) {
            $apiResponse = $e->getApiResponse();

            $content = null;
            $statusCode = null;

            if ($apiResponse) {
                $statusCode = method_exists($apiResponse, 'getStatusCode')
                    ? $apiResponse->getStatusCode()
                    : null;

                $content = method_exists($apiResponse, 'getContent')
                    ? $apiResponse->getContent()
                    : null;
            }

            Log::error('Erro MPApiException ao criar checkout Mercado Pago', [
                'pedido_id' => $pedido->id,
                'pedido_token' => $pedido->token ?? null,
                'status_code' => $statusCode,
                'response_content' => $content,
                'exception_message' => $e->getMessage(),
            ]);

            $detalhe = 'Erro desconhecido na API do Mercado Pago.';

            if (is_array($content)) {
                $causa = $content['cause'][0]['description'] ?? null;
                $mensagem = $content['message'] ?? null;
                $erro = $content['error'] ?? null;

                $detalhe = collect([$erro, $mensagem, $causa])
                    ->filter()
                    ->implode(' | ');

                if (empty($detalhe)) {
                    $detalhe = json_encode($content, JSON_UNESCAPED_UNICODE);
                }
            } elseif (is_string($content) && ! empty($content)) {
                $detalhe = $content;
            }

            throw new \Exception(
                'Erro ao criar checkout no Mercado Pago. ' .
                ($statusCode ? "Status: {$statusCode}. " : '') .
                "Detalhes: {$detalhe}"
            );
        } catch (MPException $e) {
            Log::error('Erro MPException ao criar checkout Mercado Pago', [
                'pedido_id' => $pedido->id,
                'pedido_token' => $pedido->token ?? null,
                'exception_message' => $e->getMessage(),
            ]);

            throw new \Exception('Erro de comunicação com o Mercado Pago: ' . $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Erro inesperado ao criar checkout Mercado Pago', [
                'pedido_id' => $pedido->id,
                'pedido_token' => $pedido->token ?? null,
                'exception_message' => $e->getMessage(),
            ]);

            throw new \Exception('Erro inesperado ao criar checkout: ' . $e->getMessage());
        }
    }

    public function buscarPagamentoPorId(string $paymentId): mixed
    {
        $client = new PaymentClient();

        return $client->get($paymentId);
    }
}