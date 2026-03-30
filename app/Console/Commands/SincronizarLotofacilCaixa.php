<?php

namespace App\Console\Commands;

use App\Models\LotofacilConcurso;
use App\Services\CaixaLotofacilService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SincronizarLotofacilCaixa extends Command
{
    protected $signature = 'lottus:sincronizar-lotofacil';
    protected $description = 'Consulta a API da Caixa e salva o último concurso da Lotofácil, se ainda não existir no banco';

    public function __construct(
        private readonly CaixaLotofacilService $caixaLotofacilService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $resultado = $this->caixaLotofacilService->buscarUltimoResultado();

            $numeroConcurso = (int) $resultado['numero'];

            $concursoExistente = LotofacilConcurso::where('concurso', $numeroConcurso)->first();

            if ($concursoExistente) {
                $this->info("Concurso {$numeroConcurso} já existe no banco. Nada a fazer.");
                return self::SUCCESS;
            }

            $dataApuracao = Carbon::createFromFormat('d/m/Y', $resultado['dataApuracao'])->format('Y-m-d');

            $dezenas = collect($resultado['listaDezenas'])
                ->map(fn ($dezena) => (int) $dezena)
                ->sort()
                ->values()
                ->all();

            $rateios = collect($resultado['listaRateioPremio'] ?? []);

            $faixa15 = $rateios->firstWhere('faixa', 1);
            $faixa14 = $rateios->firstWhere('faixa', 2);
            $faixa13 = $rateios->firstWhere('faixa', 3);
            $faixa12 = $rateios->firstWhere('faixa', 4);
            $faixa11 = $rateios->firstWhere('faixa', 5);

            LotofacilConcurso::create([
                'concurso' => $numeroConcurso,
                'data_sorteio' => $dataApuracao,

                'bola1' => $dezenas[0],
                'bola2' => $dezenas[1],
                'bola3' => $dezenas[2],
                'bola4' => $dezenas[3],
                'bola5' => $dezenas[4],
                'bola6' => $dezenas[5],
                'bola7' => $dezenas[6],
                'bola8' => $dezenas[7],
                'bola9' => $dezenas[8],
                'bola10' => $dezenas[9],
                'bola11' => $dezenas[10],
                'bola12' => $dezenas[11],
                'bola13' => $dezenas[12],
                'bola14' => $dezenas[13],
                'bola15' => $dezenas[14],

                'ganhadores_15_acertos' => $faixa15['numeroDeGanhadores'] ?? null,
                'rateio_15_acertos' => isset($faixa15['valorPremio']) ? (string) $faixa15['valorPremio'] : null,

                'ganhadores_14_acertos' => $faixa14['numeroDeGanhadores'] ?? null,
                'rateio_14_acertos' => isset($faixa14['valorPremio']) ? (string) $faixa14['valorPremio'] : null,

                'ganhadores_13_acertos' => $faixa13['numeroDeGanhadores'] ?? null,
                'rateio_13_acertos' => isset($faixa13['valorPremio']) ? (string) $faixa13['valorPremio'] : null,

                'ganhadores_12_acertos' => $faixa12['numeroDeGanhadores'] ?? null,
                'rateio_12_acertos' => isset($faixa12['valorPremio']) ? (string) $faixa12['valorPremio'] : null,

                'ganhadores_11_acertos' => $faixa11['numeroDeGanhadores'] ?? null,
                'rateio_11_acertos' => isset($faixa11['valorPremio']) ? (string) $faixa11['valorPremio'] : null,

                'cidade_uf' => $resultado['nomeMunicipioUFSorteio'] ?? null,
                'observacao' => $resultado['observacao'] ?? null,
                'arrecadacao_total' => isset($resultado['valorArrecadado']) ? (string) $resultado['valorArrecadado'] : null,
                'estimativa_premio' => isset($resultado['valorEstimadoProximoConcurso']) ? (string) $resultado['valorEstimadoProximoConcurso'] : null,
                'acumulado_15_acertos' => isset($resultado['valorAcumuladoProximoConcurso']) ? (string) $resultado['valorAcumuladoProximoConcurso'] : null,
                'acumulado_sorteio_especial_lotofacil_independencia' => isset($resultado['valorAcumuladoConcursoEspecial']) ? (string) $resultado['valorAcumuladoConcursoEspecial'] : null,

                'informado_manualmente' => false,
            ]);

            $this->info("Concurso {$numeroConcurso} salvo com sucesso.");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Erro ao sincronizar Lotofácil: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}