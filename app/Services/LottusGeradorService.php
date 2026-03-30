<?php

namespace App\Services;

use App\Models\LotofacilConcurso;
use Illuminate\Support\Collection;
use RuntimeException;

class LottusGeradorService
{
    private array $moldura = [1, 2, 3, 4, 5, 6, 10, 11, 15, 16, 20, 21, 22, 23, 24, 25];
    private array $primos = [2, 3, 5, 7, 11, 13, 17, 19, 23];

    public function gerar(LotofacilConcurso $concursoBase): array
    {
        $concursosOrdenados = LotofacilConcurso::orderBy('concurso')->get();

        if ($concursosOrdenados->count() < 50) {
            throw new RuntimeException('É necessário ter pelo menos 50 concursos para gerar a análise.');
        }

        $metricas = $this->calcularMetricas($concursosOrdenados, $concursoBase);

        $candidatos = [];

        for ($i = 0; $i < 7000; $i++) {
            $jogo = $this->sortearComPeso($metricas['scores']);
            sort($jogo);

            if (! $this->passaNosFiltros($jogo, $metricas, $concursoBase->dezenas)) {
                continue;
            }

            $scoreJogo = $this->pontuarJogo($jogo, $metricas, $concursoBase->dezenas);

            $candidatos[] = [
                'dezenas' => $jogo,
                'score' => $scoreJogo,
                'analise' => $this->resumoJogo($jogo, $metricas, $concursoBase->dezenas),
            ];
        }

        if (empty($candidatos)) {
            throw new RuntimeException('Nenhum jogo válido foi encontrado com os filtros atuais.');
        }

        usort($candidatos, fn ($a, $b) => $b['score'] <=> $a['score']);

        $top = array_slice($candidatos, 0, min(40, count($candidatos)));
        $selecionado = $top[array_rand($top)];

        return $selecionado;
    }

    private function calcularMetricas(Collection $concursosOrdenados, LotofacilConcurso $concursoBase): array
    {
        $todos = $concursosOrdenados->values();
        $desc = $concursosOrdenados->sortByDesc('concurso')->values();

        $ultimos20 = $desc->take(min(20, $desc->count()));
        $ultimos100 = $desc->take(min(100, $desc->count()));
        $ultimos500 = $desc->take(min(500, $desc->count()));

        $freq20 = array_fill(1, 25, 0);
        $freq100 = array_fill(1, 25, 0);
        $freq500 = array_fill(1, 25, 0);
        $freqTotal = array_fill(1, 25, 0);
        $atraso = array_fill(1, 25, 0);

        foreach ($todos as $concurso) {
            foreach ($concurso->dezenas as $dezena) {
                $freqTotal[$dezena]++;
            }
        }

        foreach ($ultimos20 as $concurso) {
            foreach ($concurso->dezenas as $dezena) {
                $freq20[$dezena]++;
            }
        }

        foreach ($ultimos100 as $concurso) {
            foreach ($concurso->dezenas as $dezena) {
                $freq100[$dezena]++;
            }
        }

        foreach ($ultimos500 as $concurso) {
            foreach ($concurso->dezenas as $dezena) {
                $freq500[$dezena]++;
            }
        }

        for ($dezena = 1; $dezena <= 25; $dezena++) {
            $gap = 0;
            foreach ($desc as $concurso) {
                if (in_array($dezena, $concurso->dezenas, true)) {
                    break;
                }
                $gap++;
            }
            $atraso[$dezena] = $gap;
        }

        $concursosParaSoma = $desc->take(min(100, $desc->count()));
        $somasHistoricas = $concursosParaSoma->map(fn ($c) => array_sum($c->dezenas))->values();

        $mediaSoma = (int) round($somasHistoricas->avg());
        $minSoma = max(170, $mediaSoma - 16);
        $maxSoma = min(220, $mediaSoma + 16);

        $scores = [];
        for ($dezena = 1; $dezena <= 25; $dezena++) {
            $scoreCurto = ($freq20[$dezena] / max($ultimos20->count(), 1)) * 100;
            $scoreMedio = ($freq100[$dezena] / max($ultimos100->count(), 1)) * 100;
            $scoreLongo = ($freq500[$dezena] / max($ultimos500->count(), 1)) * 100;

            $scoreBase = ($scoreCurto * 0.50) + ($scoreMedio * 0.30) + ($scoreLongo * 0.20);

            $bonusAtraso = $this->calcularBonusAtraso($atraso[$dezena]);

            $penalidadeExcessoRecente = 0;
            if ($freq20[$dezena] >= 15) {
                $penalidadeExcessoRecente = 4;
            }

            $scores[$dezena] = round($scoreBase + $bonusAtraso - $penalidadeExcessoRecente, 4);
        }

        arsort($scores);

        $topQuentes = array_slice(array_keys($scores), 0, 10);
        $topAtrasadas = collect($atraso)
            ->sortDesc()
            ->keys()
            ->take(10)
            ->map(fn ($key) => (int) $key)
            ->all();

        return [
            'freq_20' => $freq20,
            'freq_100' => $freq100,
            'freq_500' => $freq500,
            'freq_total' => $freqTotal,
            'atraso' => $atraso,
            'scores' => $scores,
            'top_quentes' => $topQuentes,
            'top_atrasadas' => $topAtrasadas,
            'media_soma' => $mediaSoma,
            'min_soma' => $minSoma,
            'max_soma' => $maxSoma,
        ];
    }

    private function calcularBonusAtraso(int $atraso): float
    {
        return match (true) {
            $atraso <= 1 => 0.4,
            $atraso <= 3 => 2.8,
            $atraso <= 5 => 4.6,
            $atraso <= 7 => 5.4,
            $atraso <= 9 => 3.2,
            default => 1.2,
        };
    }

    private function sortearComPeso(array $scores): array
    {
        $pool = $scores;
        $selecionadas = [];

        while (count($selecionadas) < 15) {
            $dezena = $this->weightedPick($pool);
            $selecionadas[] = $dezena;
            unset($pool[$dezena]);
        }

        return $selecionadas;
    }

    private function weightedPick(array $weights): int
    {
        $total = array_sum($weights);

        if ($total <= 0) {
            return (int) array_key_first($weights);
        }

        $rand = (mt_rand() / mt_getrandmax()) * $total;

        $acumulado = 0;
        foreach ($weights as $numero => $peso) {
            $acumulado += $peso;
            if ($rand <= $acumulado) {
                return (int) $numero;
            }
        }

        return (int) array_key_first($weights);
    }

    private function passaNosFiltros(array $jogo, array $metricas, array $ultimoConcurso): bool
    {
        $pares = count(array_filter($jogo, fn ($n) => $n % 2 === 0));
        if (! in_array($pares, [6, 7, 8, 9], true)) {
            return false;
        }

        $soma = array_sum($jogo);
        if ($soma < $metricas['min_soma'] || $soma > $metricas['max_soma']) {
            return false;
        }

        $faixas = [
            count(array_filter($jogo, fn ($n) => $n >= 1 && $n <= 5)),
            count(array_filter($jogo, fn ($n) => $n >= 6 && $n <= 10)),
            count(array_filter($jogo, fn ($n) => $n >= 11 && $n <= 15)),
            count(array_filter($jogo, fn ($n) => $n >= 16 && $n <= 20)),
            count(array_filter($jogo, fn ($n) => $n >= 21 && $n <= 25)),
        ];

        foreach ($faixas as $qtd) {
            if ($qtd < 2 || $qtd > 4) {
                return false;
            }
        }

        if ($this->maiorSequencia($jogo) > 4) {
            return false;
        }

        if ($this->temLinhaOuColunaCompleta($jogo)) {
            return false;
        }

        $repetidasUltimo = count(array_intersect($jogo, $ultimoConcurso));
        if ($repetidasUltimo < 6 || $repetidasUltimo > 10) {
            return false;
        }

        $qtdQuentes = count(array_intersect($jogo, $metricas['top_quentes']));
        if ($qtdQuentes < 4 || $qtdQuentes > 8) {
            return false;
        }

        $qtdAtrasadas = count(array_intersect($jogo, $metricas['top_atrasadas']));
        if ($qtdAtrasadas < 2 || $qtdAtrasadas > 5) {
            return false;
        }

        $baixoRange = count(array_filter($jogo, fn ($n) => $n <= 15));
        if ($baixoRange > 10) {
            return false;
        }

        $qtdMoldura = $this->quantidadeNaMoldura($jogo);
        if ($qtdMoldura < 9 || $qtdMoldura > 11) {
            return false;
        }

        $qtdPrimos = $this->quantidadePrimos($jogo);
        if ($qtdPrimos < 5 || $qtdPrimos > 6) {
            return false;
        }

        if (! $this->quadrantesValidos($jogo)) {
            return false;
        }

        return true;
    }

    private function pontuarJogo(array $jogo, array $metricas, array $ultimoConcurso): float
    {
        $scoreBase = array_sum(array_map(fn ($n) => $metricas['scores'][$n], $jogo));

        $pares = count(array_filter($jogo, fn ($n) => $n % 2 === 0));
        $soma = array_sum($jogo);
        $repetidasUltimo = count(array_intersect($jogo, $ultimoConcurso));
        $quentes = count(array_intersect($jogo, $metricas['top_quentes']));
        $atrasadas = count(array_intersect($jogo, $metricas['top_atrasadas']));
        $moldura = $this->quantidadeNaMoldura($jogo);
        $primos = $this->quantidadePrimos($jogo);
        $maiorSequencia = $this->maiorSequencia($jogo);

        $bonus = 0;

        if (in_array($pares, [7, 8], true)) {
            $bonus += 8;
        } elseif (in_array($pares, [6, 9], true)) {
            $bonus += 4;
        }

        $distSoma = abs($metricas['media_soma'] - $soma);
        $bonus += max(0, 10 - ($distSoma / 2));

        if ($quentes >= 4 && $quentes <= 8) {
            $bonus += 8;
        }

        if ($atrasadas >= 2 && $atrasadas <= 5) {
            $bonus += 7;
        }

        if ($repetidasUltimo >= 6 && $repetidasUltimo <= 10) {
            $bonus += 5;
        }

        if ($moldura >= 9 && $moldura <= 11) {
            $bonus += 6;
        }

        if ($primos >= 5 && $primos <= 6) {
            $bonus += 5;
        }

        if ($this->quadrantesValidos($jogo)) {
            $bonus += 5;
        }

        $faixaBonus = 0;
        $faixas = [
            count(array_filter($jogo, fn ($n) => $n >= 1 && $n <= 5)),
            count(array_filter($jogo, fn ($n) => $n >= 6 && $n <= 10)),
            count(array_filter($jogo, fn ($n) => $n >= 11 && $n <= 15)),
            count(array_filter($jogo, fn ($n) => $n >= 16 && $n <= 20)),
            count(array_filter($jogo, fn ($n) => $n >= 21 && $n <= 25)),
        ];

        foreach ($faixas as $qtd) {
            if ($qtd >= 2 && $qtd <= 4) {
                $faixaBonus += 1.5;
            }
        }

        $penalidade = 0;

        if ($maiorSequencia === 4) {
            $penalidade += 3;
        } elseif ($maiorSequencia >= 5) {
            $penalidade += 15;
        }

        if ($this->temLinhaOuColunaCompleta($jogo)) {
            $penalidade += 15;
        }

        return round($scoreBase + $bonus + $faixaBonus - $penalidade, 2);
    }

    private function resumoJogo(array $jogo, array $metricas, array $ultimoConcurso): array
    {
        $pares = count(array_filter($jogo, fn ($n) => $n % 2 === 0));
        $impares = 15 - $pares;

        return [
            'pares' => $pares,
            'impares' => $impares,
            'soma' => array_sum($jogo),
            'repetidas_ultimo_concurso' => count(array_intersect($jogo, $ultimoConcurso)),
            'quentes' => count(array_intersect($jogo, $metricas['top_quentes'])),
            'atrasadas' => count(array_intersect($jogo, $metricas['top_atrasadas'])),
            'moldura' => $this->quantidadeNaMoldura($jogo),
            'centro' => 15 - $this->quantidadeNaMoldura($jogo),
            'primos' => $this->quantidadePrimos($jogo),
            'maior_sequencia' => $this->maiorSequencia($jogo),
            'top_quentes' => $metricas['top_quentes'],
            'top_atrasadas' => $metricas['top_atrasadas'],
        ];
    }

    private function quantidadeNaMoldura(array $jogo): int
    {
        return count(array_intersect($jogo, $this->moldura));
    }

    private function quantidadePrimos(array $jogo): int
    {
        return count(array_intersect($jogo, $this->primos));
    }

    private function quadrantesValidos(array $jogo): bool
    {
        $quadrantes = [
            1 => [1, 2, 3, 6, 7, 8, 11, 12, 13],
            2 => [4, 5, 9, 10, 14, 15],
            3 => [16, 17, 18, 21, 22, 23],
            4 => [19, 20, 24, 25],
        ];

        foreach ($quadrantes as $quadrante) {
            $qtd = count(array_intersect($jogo, $quadrante));

            if ($qtd === 0 || $qtd > 5) {
                return false;
            }
        }

        return true;
    }

    private function maiorSequencia(array $jogo): int
    {
        sort($jogo);

        $maior = 1;
        $atual = 1;

        for ($i = 1; $i < count($jogo); $i++) {
            if ($jogo[$i] === $jogo[$i - 1] + 1) {
                $atual++;
                $maior = max($maior, $atual);
            } else {
                $atual = 1;
            }
        }

        return $maior;
    }

    private function temLinhaOuColunaCompleta(array $jogo): bool
    {
        $linhas = [
            [1, 2, 3, 4, 5],
            [6, 7, 8, 9, 10],
            [11, 12, 13, 14, 15],
            [16, 17, 18, 19, 20],
            [21, 22, 23, 24, 25],
        ];

        $colunas = [
            [1, 6, 11, 16, 21],
            [2, 7, 12, 17, 22],
            [3, 8, 13, 18, 23],
            [4, 9, 14, 19, 24],
            [5, 10, 15, 20, 25],
        ];

        foreach ($linhas as $linha) {
            if (count(array_intersect($jogo, $linha)) === 5) {
                return true;
            }
        }

        foreach ($colunas as $coluna) {
            if (count(array_intersect($jogo, $coluna)) === 5) {
                return true;
            }
        }

        return false;
    }
}