<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class CaixaLotofacilService
{
    private string $url = 'https://servicebus2.caixa.gov.br/portaldeloterias/api/lotofacil';

    public function buscarUltimoResultado(): array
    {
        $response = Http::timeout(20)
            ->withHeaders([
                'Accept' => 'application/json, text/plain, */*',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                'Referer' => 'https://loterias.caixa.gov.br/',
                'Origin' => 'https://loterias.caixa.gov.br',
                'Connection' => 'keep-alive',
            ])
            ->get($this->url);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Erro na API Caixa | Status: ' . $response->status()
            );
        }

        $data = $response->json();

        if (
            ! is_array($data) ||
            empty($data['numero']) ||
            empty($data['dataApuracao']) ||
            empty($data['listaDezenas'])
        ) {
            throw new RuntimeException('Resposta inválida da API da Caixa.');
        }

        if (count($data['listaDezenas']) !== 15) {
            throw new RuntimeException('Quantidade inválida de dezenas.');
        }

        return $data;
    }
}