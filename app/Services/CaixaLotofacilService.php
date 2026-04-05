<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class CaixaLotofacilService
{
    private string $url = 'https://wild-sky-86a7.wilsonluiz31051991.workers.dev/';

    public function buscarUltimoResultado(): array
    {
        $response = Http::timeout(20)
            ->withOptions([
                'verify' => app()->environment('local') ? false : true,
            ])
            ->acceptJson()
            ->get($this->url);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Erro no proxy da Caixa | Status: ' . $response->status()
            );
        }

        $data = $response->json();

        if (
            ! is_array($data) ||
            empty($data['numero']) ||
            empty($data['dataApuracao']) ||
            empty($data['listaDezenas'])
        ) {
            throw new RuntimeException('A resposta do proxy da Caixa veio incompleta ou inválida.');
        }

        if (count($data['listaDezenas']) !== 15) {
            throw new RuntimeException('O proxy retornou uma quantidade inválida de dezenas.');
        }

        return $data;
    }
}