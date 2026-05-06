<?php

namespace App\Console\Commands;

use App\Events\LotofacilConcursoSincronizado;
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

            if (empty($resultado) || !isset($resultado['numero'], $resultado['dataApuracao'], $resultado['listaDezenas'])) {
                $this->error('A API retornou dados incompletos para o resultado da Lotofácil.');
                return self::FAILURE;
            }

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

            if (count($dezenas) !== 15) {
                $this->error("O concurso {$numeroConcurso} retornou uma quantidade inválida de dezenas.");
                return self::FAILURE;
            }

            $rateios = collect($resultado['listaRateioPremio'] ?? []);

            $faixa15 = $rateios->firstWhere('faixa', 1) ?? [];
            $faixa14 = $rateios->firstWhere('faixa', 2) ?? [];
            $faixa13 = $rateios->firstWhere('faixa', 3) ?? [];
            $faixa12 = $rateios->firstWhere('faixa', 4) ?? [];
            $faixa11 = $rateios->firstWhere('faixa', 5) ?? [];

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

                'ganhadores_15_acertos' => (int) ($faixa15['numeroDeGanhadores'] ?? 0),
                'rateio_15_acertos' => (float) ($faixa15['valorPremio'] ?? 0),

                'ganhadores_14_acertos' => (int) ($faixa14['numeroDeGanhadores'] ?? 0),
                'rateio_14_acertos' => (float) ($faixa14['valorPremio'] ?? 0),

                'ganhadores_13_acertos' => (int) ($faixa13['numeroDeGanhadores'] ?? 0),
                'rateio_13_acertos' => (float) ($faixa13['valorPremio'] ?? 0),

                'ganhadores_12_acertos' => (int) ($faixa12['numeroDeGanhadores'] ?? 0),
                'rateio_12_acertos' => (float) ($faixa12['valorPremio'] ?? 0),

                'ganhadores_11_acertos' => (int) ($faixa11['numeroDeGanhadores'] ?? 0),
                'rateio_11_acertos' => (float) ($faixa11['valorPremio'] ?? 0),

                'cidade_uf' => $resultado['nomeMunicipioUFSorteio'] ?? null,
                'observacao' => $resultado['observacao'] ?? null,

                'arrecadacao_total' => (float) ($resultado['valorArrecadado'] ?? 0),
                'estimativa_premio' => (float) ($resultado['valorEstimadoProximoConcurso'] ?? 0),
                'acumulado_15_acertos' => (float) ($resultado['valorAcumuladoProximoConcurso'] ?? 0),
                'acumulado_sorteio_especial_lotofacil_independencia' => (float) ($resultado['valorAcumuladoConcursoEspecial'] ?? 0),

                'informado_manualmente' => false,
            ]);

            LotofacilConcursoSincronizado::dispatch($numeroConcurso);

            $this->info("Concurso {$numeroConcurso} salvo com sucesso.");
            $this->info("Evento de aprendizado adaptativo disparado para o concurso {$numeroConcurso}.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'ainda não disponível')) {
                $this->info($e->getMessage());
                return self::SUCCESS;
            }

            $this->error('Erro ao sincronizar Lotofácil: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
