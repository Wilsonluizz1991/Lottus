<?php

namespace App\Console\Commands;

use App\Models\LotofacilConcurso;
use App\Services\Lottus\Fechamento\FechamentoEngine;
use Illuminate\Console\Command;

class TestarDuplicidadeFechamento extends Command
{
    protected $signature = 'lottus:testar-duplicidade-fechamento {--dezenas=18}';

    protected $description = 'Testa se duas gerações reais de fechamento saem idênticas';

    public function handle(FechamentoEngine $engine): int
    {
        $quantidadeDezenas = (int) $this->option('dezenas');

        $concursoBase = LotofacilConcurso::query()
            ->orderByDesc('concurso')
            ->first();

        if (! $concursoBase) {
            $this->error('Nenhum concurso base encontrado.');
            return self::FAILURE;
        }

        $this->info('Testando duplicidade de fechamento...');
        $this->line('Concurso base: ' . $concursoBase->concurso);
        $this->line('Dezenas: ' . $quantidadeDezenas);

        $resultado1 = $engine->generate([
            'email' => 'teste-duplicidade-1@lottus.local',
            'quantidade_dezenas' => $quantidadeDezenas,
            'concurso_base' => $concursoBase,
        ]);

        $resultado2 = $engine->generate([
            'email' => 'teste-duplicidade-2@lottus.local',
            'quantidade_dezenas' => $quantidadeDezenas,
            'concurso_base' => $concursoBase,
        ]);

        $jogos1 = $this->extractJogos($resultado1);
        $jogos2 = $this->extractJogos($resultado2);

        $hash1 = $this->portfolioHash($jogos1);
        $hash2 = $this->portfolioHash($jogos2);

        $this->newLine();
        $this->line('Hash lote 1: ' . $hash1);
        $this->line('Hash lote 2: ' . $hash2);

        if ($hash1 === $hash2) {
            $this->error('FALHA: os dois fechamentos saíram idênticos.');
            return self::FAILURE;
        }

        $this->info('OK: os dois fechamentos saíram diferentes.');
        return self::SUCCESS;
    }

    protected function extractJogos(array $resultado): array
    {
        if (isset($resultado['jogos']) && is_array($resultado['jogos'])) {
            return $resultado['jogos'];
        }

        if (isset($resultado['pedido']['jogos']) && is_array($resultado['pedido']['jogos'])) {
            return $resultado['pedido']['jogos'];
        }

        if (isset($resultado['fechamento']['jogos']) && is_array($resultado['fechamento']['jogos'])) {
            return $resultado['fechamento']['jogos'];
        }

        foreach ($resultado as $value) {
            if (is_array($value)) {
                $nested = $this->extractJogos($value);

                if (! empty($nested)) {
                    return $nested;
                }
            }
        }

        return [];
    }

    protected function portfolioHash(array $jogos): string
    {
        $normalized = [];

        foreach ($jogos as $jogo) {
            $dezenas = $jogo['dezenas'] ?? $jogo;

            if (! is_array($dezenas)) {
                continue;
            }

            $dezenas = array_values(array_unique(array_map('intval', $dezenas)));
            sort($dezenas);

            $normalized[] = implode('-', $dezenas);
        }

        sort($normalized);

        return hash('sha256', implode('|', $normalized));
    }
}