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
            ->acceptJson()
            ->get($this->url);

        if (! $response->successful()) {
            throw new RuntimeException('Não foi possível consultar a API da Caixa.');
        }

        $data = $response->json();

        if (
            ! is_array($data) ||
            empty($data['numero']) ||
            empty($data['dataApuracao']) ||
            empty($data['listaDezenas'])
        ) {
            throw new RuntimeException('A resposta da API da Caixa veio incompleta ou inválida.');
        }

        if (count($data['listaDezenas']) !== 15) {
            throw new RuntimeException('A API retornou uma quantidade inválida de dezenas.');
        }

        return $data;
    }
}