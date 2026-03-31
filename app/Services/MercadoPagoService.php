<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MercadoPagoService
{
    protected string $baseUrl;
    protected string $accessToken;
    protected ?string $appUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.mercado_pago.base_url'), '/');
        $this->accessToken = (string) config('services.mercado_pago.access_token');
        $this->appUrl = config('services.mercado_pago.app_url');
    }

    protected function client()
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->accessToken)
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }

    public function isConfigured(): bool
    {
        return !empty($this->accessToken);
    }

    public function canSendBackUrls(): bool
    {
        return filled($this->appUrl) && Str::startsWith($this->appUrl, 'https://');
    }

    public function generateExternalReference(?string $prefix = 'LTT'): string
    {
        return $prefix . '-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(8));
    }

    public function createPreference(array $data): array
    {
        $externalReference = $data['external_reference'] ?? $this->generateExternalReference();

        $payload = [
            'items' => $data['items'],
            'external_reference' => $externalReference,
            'payer' => array_filter([
                'name'  => $data['payer']['name'] ?? null,
                'email' => $data['payer']['email'] ?? null,
            ]),
            'statement_descriptor' => $data['statement_descriptor'] ?? null,
            'auto_return' => 'approved',
            'expires' => false,
            'payment_methods' => [
                'excluded_payment_methods' => $data['excluded_payment_methods'] ?? [],
                'excluded_payment_types' => $data['excluded_payment_types'] ?? [],
                'installments' => $data['installments'] ?? 12,
            ],
            'metadata' => array_filter([
                'external_reference' => $externalReference,
                'order_code' => $data['order_code'] ?? null,
                'source' => 'lottus',
            ]),
        ];

        if ($this->canSendBackUrls()) {
            $payload['back_urls'] = [
                'success' => rtrim($this->appUrl, '/') . '/pagamentos/mercado-pago/success',
                'failure' => rtrim($this->appUrl, '/') . '/pagamentos/mercado-pago/failure',
                'pending' => rtrim($this->appUrl, '/') . '/pagamentos/mercado-pago/pending',
            ];
        }

        $response = $this->client()
            ->post('/checkout/preferences', $payload)
            ->throw()
            ->json();

        $payment = Payment::create([
            'external_reference'    => $externalReference,
            'order_code'            => $data['order_code'] ?? null,
            'provider'              => 'mercado_pago',
            'provider_preference_id'=> $response['id'] ?? null,
            'payer_email'           => $data['payer']['email'] ?? null,
            'payer_name'            => $data['payer']['name'] ?? null,
            'amount'                => $this->sumItems($data['items']),
            'currency_id'           => $data['currency_id'] ?? 'BRL',
            'description'           => $data['description'] ?? ($data['items'][0]['title'] ?? 'Pagamento'),
            'local_status'          => 'preference_created',
            'items'                 => $data['items'],
            'preference_payload'    => $payload,
            'preference_response'   => $response,
            'init_point'            => $response['init_point'] ?? null,
            'sandbox_init_point'    => $response['sandbox_init_point'] ?? null,
        ]);

        return [
            'payment' => $payment,
            'preference' => $response,
        ];
    }

    public function getPreference(string $preferenceId): array
    {
        return $this->client()
            ->get("/checkout/preferences/{$preferenceId}")
            ->throw()
            ->json();
    }

    public function getPayment(string|int $paymentId): array
    {
        return $this->client()
            ->get("/v1/payments/{$paymentId}")
            ->throw()
            ->json();
    }

    public function searchPaymentsByExternalReference(string $externalReference): array
    {
        return $this->client()
            ->get('/v1/payments/search', [
                'external_reference' => $externalReference,
                'sort' => 'date_created',
                'criteria' => 'desc',
                'range' => 'date_created',
            ])
            ->throw()
            ->json();
    }

    public function syncPaymentByExternalReference(string $externalReference): ?Payment
    {
        $payment = Payment::where('external_reference', $externalReference)->first();

        if (!$payment) {
            return null;
        }

        $result = $this->searchPaymentsByExternalReference($externalReference);
        $results = Arr::get($result, 'results', []);

        if (empty($results)) {
            $payment->update([
                'last_synced_at' => now(),
            ]);

            return $payment->fresh();
        }

        $latest = $results[0];

        $payment->update([
            'provider_payment_id'     => isset($latest['id']) ? (string) $latest['id'] : $payment->provider_payment_id,
            'provider_status'         => $latest['status'] ?? null,
            'provider_status_detail'  => $latest['status_detail'] ?? null,
            'provider_collection_id'  => isset($latest['id']) ? (string) $latest['id'] : $payment->provider_collection_id,
            'payment_method_id'       => $latest['payment_method_id'] ?? null,
            'payment_type_id'         => $latest['payment_type_id'] ?? null,
            'last_payment_response'   => $latest,
            'local_status'            => $this->mapStatus($latest['status'] ?? null),
            'paid_at'                 => ($latest['status'] ?? null) === 'approved'
                                        ? now()
                                        : $payment->paid_at,
            'last_synced_at'          => now(),
        ]);

        return $payment->fresh();
    }

    public function syncPaymentByPaymentId(string|int $paymentId): ?Payment
    {
        $data = $this->getPayment($paymentId);
        $externalReference = $data['external_reference'] ?? null;

        if (!$externalReference) {
            return null;
        }

        $payment = Payment::where('external_reference', $externalReference)->first();

        if (!$payment) {
            return null;
        }

        $payment->update([
            'provider_payment_id'     => isset($data['id']) ? (string) $data['id'] : $payment->provider_payment_id,
            'provider_status'         => $data['status'] ?? null,
            'provider_status_detail'  => $data['status_detail'] ?? null,
            'provider_collection_id'  => isset($data['id']) ? (string) $data['id'] : $payment->provider_collection_id,
            'payment_method_id'       => $data['payment_method_id'] ?? null,
            'payment_type_id'         => $data['payment_type_id'] ?? null,
            'last_payment_response'   => $data,
            'local_status'            => $this->mapStatus($data['status'] ?? null),
            'paid_at'                 => ($data['status'] ?? null) === 'approved'
                                        ? now()
                                        : $payment->paid_at,
            'last_synced_at'          => now(),
        ]);

        return $payment->fresh();
    }

    public function mapStatus(?string $providerStatus): string
    {
        return match ($providerStatus) {
            'approved' => 'paid',
            'pending', 'in_process', 'in_mediation' => 'pending',
            'rejected', 'cancelled', 'refunded', 'charged_back' => 'failed',
            default => 'created',
        };
    }

    protected function sumItems(array $items): float
    {
        return collect($items)->sum(function ($item) {
            $price = (float) ($item['unit_price'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 1);
            return $price * $qty;
        });
    }
}