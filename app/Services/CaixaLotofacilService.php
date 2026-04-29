<?php

namespace App\Services;

use App\Models\LotofacilConcurso;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CaixaLotofacilService
{
    private string $url = 'https://wild-sky-86a7.wilsonluiz31051991.workers.dev/';

    public function buscarUltimoResultado(): array
    {
        $ultimoConcursoBanco = LotofacilConcurso::orderByDesc('concurso')->value('concurso');

        $proximoConcurso = $ultimoConcursoBanco
            ? ((int) $ultimoConcursoBanco + 1)
            : null;

        $response = Http::timeout(20)
            ->withOptions([
                'verify' => app()->environment('local') ? false : true,
            ])
            ->withHeaders([
                'Accept' => 'application/json',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ])
            ->acceptJson()
            ->get($this->url, array_filter([
                'concurso' => $proximoConcurso,
                '_nocache' => now()->timestamp,
            ]));

        if (! $response->successful()) {
            throw new RuntimeException(
                "Concurso {$proximoConcurso} ainda não disponível no proxy da Caixa | Status: " . $response->status()
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