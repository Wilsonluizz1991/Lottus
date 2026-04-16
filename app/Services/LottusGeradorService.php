<?php

namespace App\Services;

use App\Models\LotofacilConcurso;
use Illuminate\Support\Collection;
use RuntimeException;

class LottusGeradorService
{
    private array $moldura = [1, 2, 3, 4, 5, 6, 10, 11, 15, 16, 20, 21, 22, 23, 24, 25];
    private array $centro = [7, 8, 9, 12, 13, 14, 17, 18, 19];
    private array $primos = [2, 3, 5, 7, 11, 13, 17, 19, 23];
    private array $fibonacci = [1, 2, 3, 5, 8, 13, 21];
    private int $quantidadeDezenasJogo = 15;
    private int $minimoConcursosHistorico = 50;
    private int $tentativasGeracao = 12000;
    private int $topCandidatosElegiveis = 60;

    public function gerar(LotofacilConcurso $concursoBase): array
    {
        $concursosOrdenados = LotofacilConcurso::where('concurso', '<=', $concursoBase->concurso)
            ->orderBy('concurso')
            ->get();

        if ($concursosOrdenados->count() < $this->minimoConcursosHistorico) {
            throw new RuntimeException('É necessário ter pelo menos 50 concursos até o concurso base para gerar a análise.');
        }

        $metricas = $this->calcularMetricas($concursosOrdenados, $concursoBase);

        $candidatos = [];
        $assinaturas = [];

        for ($i = 0; $i < $this->tentativasGeracao; $i++) {
            $jogo = $this->sortearComPeso($metricas['scores']);
            sort($jogo);

            $assinatura = implode('-', $jogo);

            if (isset($assinaturas[$assinatura])) {
                continue;
            }

            if (! $this->passaNosFiltros($jogo, $metricas, $concursoBase->dezenas)) {
                continue;
            }

            $assinaturas[$assinatura] = true;

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

        $top = array_slice($candidatos, 0, min($this->topCandidatosElegiveis, count($candidatos)));
        $topDiversificado = $this->selecionarTopDiversificado($top);

        if (empty($topDiversificado)) {
            $topDiversificado = $top;
        }

        return $topDiversificado[array_rand($topDiversificado)];
    }

    private function calcularMetricas(Collection $concursosOrdenados, LotofacilConcurso $concursoBase): array
    {
        $todos = $concursosOrdenados->values();
        $desc = $concursosOrdenados->sortByDesc('concurso')->values();

        $historicoSemBase = $todos->filter(fn ($c) => $c->concurso < $concursoBase->concurso)->values();
        $descSemBase = $historicoSemBase->sortByDesc('concurso')->values();

        if ($historicoSemBase->count() < $this->minimoConcursosHistorico) {
            throw new RuntimeException('É necessário ter pelo menos 50 concursos anteriores ao concurso base para gerar a análise.');
        }

        $janela15 = $descSemBase->take(min(15, $descSemBase->count()));
        $janela30 = $descSemBase->take(min(30, $descSemBase->count()));
        $janela60 = $descSemBase->take(min(60, $descSemBase->count()));
        $janela120 = $descSemBase->take(min(120, $descSemBase->count()));
        $janela300 = $descSemBase->take(min(300, $descSemBase->count()));

        $freq15 = array_fill(1, 25, 0);
        $freq30 = array_fill(1, 25, 0);
        $freq60 = array_fill(1, 25, 0);
        $freq120 = array_fill(1, 25, 0);
        $freq300 = array_fill(1, 25, 0);
        $freqTotal = array_fill(1, 25, 0);
        $atraso = array_fill(1, 25, 0);

        foreach ($historicoSemBase as $concurso) {
            foreach ($concurso->dezenas as $dezena) {
                $freqTotal[$dezena]++;
            }
        }

        foreach ($janela15 as $concurso) {
            foreach ($concurso->dezenas as $dezena) {
                $freq15[$dezena]++;
            }
        }

        foreach ($janela30 as $concurso) {
            foreach ($concurso->dezenas as $dezena) {
                $freq30[$dezena]++;
            }
        }

        foreach ($janela60 as $concurso) {
            foreach ($concurso->dezenas as $dezena) {
                $freq60[$dezena]++;
            }
        }

        foreach ($janela120 as $concurso) {
            foreach ($concurso->dezenas as $dezena) {
                $freq120[$dezena]++;
            }
        }

        foreach ($janela300 as $concurso) {
            foreach ($concurso->dezenas as $dezena) {
                $freq300[$dezena]++;
            }
        }

        for ($dezena = 1; $dezena <= 25; $dezena++) {
            $gap = 0;
            foreach ($descSemBase as $concurso) {
                if (in_array($dezena, $concurso->dezenas, true)) {
                    break;
                }
                $gap++;
            }
            $atraso[$dezena] = $gap;
        }

        $concursosEstatisticos = $descSemBase->take(min(120, $descSemBase->count()));

        $somasHistoricas = $concursosEstatisticos->map(fn ($c) => array_sum($c->dezenas))->values();
        $paresHistoricos = $concursosEstatisticos->map(fn ($c) => $this->quantidadePares($c->dezenas))->values();
        $primosHistoricos = $concursosEstatisticos->map(fn ($c) => $this->quantidadePrimos($c->dezenas))->values();
        $molduraHistorica = $concursosEstatisticos->map(fn ($c) => $this->quantidadeNaMoldura($c->dezenas))->values();
        $centroHistorico = $concursosEstatisticos->map(fn ($c) => $this->quantidadeNoCentro($c->dezenas))->values();
        $fibonacciHistorico = $concursosEstatisticos->map(fn ($c) => $this->quantidadeFibonacci($c->dezenas))->values();
        $sequenciasHistoricas = $concursosEstatisticos->map(fn ($c) => $this->maiorSequencia($c->dezenas))->values();

        $repeticoesHistoricas = collect();
        for ($i = 0; $i < $descSemBase->count() - 1; $i++) {
            $atual = $descSemBase[$i]->dezenas;
            $anterior = $descSemBase[$i + 1]->dezenas;
            $repeticoesHistoricas->push(count(array_intersect($atual, $anterior)));
        }

        $faixasHistoricas = $concursosEstatisticos->map(function ($c) {
            return $this->contagemFaixas($c->dezenas);
        })->values();

        $faixa1Historica = $faixasHistoricas->map(fn ($faixas) => $faixas[0])->values();
        $faixa2Historica = $faixasHistoricas->map(fn ($faixas) => $faixas[1])->values();
        $faixa3Historica = $faixasHistoricas->map(fn ($faixas) => $faixas[2])->values();
        $faixa4Historica = $faixasHistoricas->map(fn ($faixas) => $faixas[3])->values();
        $faixa5Historica = $faixasHistoricas->map(fn ($faixas) => $faixas[4])->values();

        $mediaSoma = (int) round($somasHistoricas->avg());

        $intervaloSoma = $this->intervaloHistorico($somasHistoricas, 0.15, 0.85, 170, 220);
        $intervaloPares = $this->intervaloHistorico($paresHistoricos, 0.12, 0.88, 5, 10);
        $intervaloPrimos = $this->intervaloHistorico($primosHistoricos, 0.12, 0.88, 3, 8);
        $intervaloMoldura = $this->intervaloHistorico($molduraHistorica, 0.12, 0.88, 8, 12);
        $intervaloCentro = $this->intervaloHistorico($centroHistorico, 0.12, 0.88, 3, 7);
        $intervaloFibonacci = $this->intervaloHistorico($fibonacciHistorico, 0.12, 0.88, 2, 6);
        $intervaloRepetidas = $this->intervaloHistorico($repeticoesHistoricas, 0.10, 0.90, 5, 11);

        $faixasIntervalos = [
            $this->intervaloHistorico($faixa1Historica, 0.08, 0.92, 1, 5),
            $this->intervaloHistorico($faixa2Historica, 0.08, 0.92, 1, 5),
            $this->intervaloHistorico($faixa3Historica, 0.08, 0.92, 1, 5),
            $this->intervaloHistorico($faixa4Historica, 0.08, 0.92, 1, 5),
            $this->intervaloHistorico($faixa5Historica, 0.08, 0.92, 1, 5),
        ];

        $maxAtraso = max($atraso) ?: 1;
        $mediaFreqGlobal = $historicoSemBase->count() > 0 ? (($historicoSemBase->count() * 15) / 25) : 1;

        $scores = [];
        for ($dezena = 1; $dezena <= 25; $dezena++) {
            $p15 = $freq15[$dezena] / max($janela15->count(), 1);
            $p30 = $freq30[$dezena] / max($janela30->count(), 1);
            $p60 = $freq60[$dezena] / max($janela60->count(), 1);
            $p120 = $freq120[$dezena] / max($janela120->count(), 1);
            $p300 = $freq300[$dezena] / max($janela300->count(), 1);
            $pTotal = $freqTotal[$dezena] / max($historicoSemBase->count(), 1);

            $forcaRecente = ($p15 * 0.34) + ($p30 * 0.26) + ($p60 * 0.22) + ($p120 * 0.12) + ($p300 * 0.06);
            $estabilidade = ($p300 * 0.60) + ($pTotal * 0.40);
            $momentum = max(0, $p30 - $p120);
            $desvioGlobal = ($freqTotal[$dezena] - $mediaFreqGlobal) / max($mediaFreqGlobal, 1);
            $indiceAtraso = $atraso[$dezena] / $maxAtraso;

            $score = 0;
            $score += $forcaRecente * 52;
            $score += $estabilidade * 26;
            $score += $momentum * 12;
            $score += $this->calcularBonusAtraso($atraso[$dezena], $maxAtraso);
            $score += max(-3, min(3, $desvioGlobal * 3.2));

            if ($p15 >= 0.80 && $p30 >= 0.70) {
                $score -= 2.5;
            }

            if ($indiceAtraso >= 0.75) {
                $score += 2.8;
            }

            $scores[$dezena] = round(max($score, 0.01), 6);
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
            'freq_15' => $freq15,
            'freq_30' => $freq30,
            'freq_60' => $freq60,
            'freq_120' => $freq120,
            'freq_300' => $freq300,
            'freq_total' => $freqTotal,
            'atraso' => $atraso,
            'scores' => $scores,
            'top_quentes' => $topQuentes,
            'top_atrasadas' => $topAtrasadas,
            'media_soma' => $mediaSoma,
            'intervalo_soma' => $intervaloSoma,
            'intervalo_pares' => $intervaloPares,
            'intervalo_primos' => $intervaloPrimos,
            'intervalo_moldura' => $intervaloMoldura,
            'intervalo_centro' => $intervaloCentro,
            'intervalo_fibonacci' => $intervaloFibonacci,
            'intervalo_repetidas' => $intervaloRepetidas,
            'intervalos_faixas' => $faixasIntervalos,
            'maior_sequencia_maxima' => min(5, max(4, (int) $sequenciasHistoricas->sort()->values()[max((int) floor(($sequenciasHistoricas->count() - 1) * 0.88), 0)])),
            'ultimo_concurso' => $historicoSemBase->last()?->dezenas ?? [],
            'ultimos_5_concursos' => $descSemBase->take(5)->pluck('dezenas')->all(),
        ];
    }

    private function calcularBonusAtraso(int $atraso, int $maxAtraso): float
    {
        $proporcao = $maxAtraso > 0 ? ($atraso / $maxAtraso) : 0;

        return match (true) {
            $proporcao <= 0.08 => 0.2,
            $proporcao <= 0.20 => 1.2,
            $proporcao <= 0.35 => 2.8,
            $proporcao <= 0.55 => 4.5,
            $proporcao <= 0.75 => 5.3,
            default => 3.4,
        };
    }

    private function sortearComPeso(array $scores): array
    {
        $pool = $scores;
        $selecionadas = [];

        while (count($selecionadas) < $this->quantidadeDezenasJogo) {
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
        $pares = $this->quantidadePares($jogo);
        if (! $this->estaNoIntervalo($pares, $metricas['intervalo_pares'])) {
            return false;
        }

        $soma = array_sum($jogo);
        if (! $this->estaNoIntervalo($soma, $metricas['intervalo_soma'])) {
            return false;
        }

        $faixas = $this->contagemFaixas($jogo);
        foreach ($faixas as $indice => $qtd) {
            if (! $this->estaNoIntervalo($qtd, $metricas['intervalos_faixas'][$indice])) {
                return false;
            }
        }

        if ($this->maiorSequencia($jogo) > $metricas['maior_sequencia_maxima']) {
            return false;
        }

        if ($this->temLinhaOuColunaCompleta($jogo)) {
            return false;
        }

        if ($this->temDiagonalCompleta($jogo)) {
            return false;
        }

        $repetidasUltimo = count(array_intersect($jogo, $ultimoConcurso));
        if (! $this->estaNoIntervalo($repetidasUltimo, $metricas['intervalo_repetidas'])) {
            return false;
        }

        $repetidasRecentes = $this->repeticoesComUltimosConcursos($jogo, $metricas['ultimos_5_concursos']);
        if ($repetidasRecentes['maxima'] > 11) {
            return false;
        }

        $qtdQuentes = count(array_intersect($jogo, $metricas['top_quentes']));
        if ($qtdQuentes < 3 || $qtdQuentes > 8) {
            return false;
        }

        $qtdAtrasadas = count(array_intersect($jogo, $metricas['top_atrasadas']));
        if ($qtdAtrasadas < 2 || $qtdAtrasadas > 6) {
            return false;
        }

        $qtdMoldura = $this->quantidadeNaMoldura($jogo);
        if (! $this->estaNoIntervalo($qtdMoldura, $metricas['intervalo_moldura'])) {
            return false;
        }

        $qtdCentro = $this->quantidadeNoCentro($jogo);
        if (! $this->estaNoIntervalo($qtdCentro, $metricas['intervalo_centro'])) {
            return false;
        }

        $qtdPrimos = $this->quantidadePrimos($jogo);
        if (! $this->estaNoIntervalo($qtdPrimos, $metricas['intervalo_primos'])) {
            return false;
        }

        $qtdFibonacci = $this->quantidadeFibonacci($jogo);
        if (! $this->estaNoIntervalo($qtdFibonacci, $metricas['intervalo_fibonacci'])) {
            return false;
        }

        if (! $this->quadrantesValidos($jogo)) {
            return false;
        }

        if (! $this->distribuicaoLinhasColunasValida($jogo)) {
            return false;
        }

        if ($this->temPadraoSimetricoExcessivo($jogo)) {
            return false;
        }

        return true;
    }

    private function pontuarJogo(array $jogo, array $metricas, array $ultimoConcurso): float
    {
        $scoreBase = array_sum(array_map(fn ($n) => $metricas['scores'][$n], $jogo));

        $pares = $this->quantidadePares($jogo);
        $soma = array_sum($jogo);
        $repetidasUltimo = count(array_intersect($jogo, $ultimoConcurso));
        $quentes = count(array_intersect($jogo, $metricas['top_quentes']));
        $atrasadas = count(array_intersect($jogo, $metricas['top_atrasadas']));
        $moldura = $this->quantidadeNaMoldura($jogo);
        $centro = $this->quantidadeNoCentro($jogo);
        $primos = $this->quantidadePrimos($jogo);
        $fibonacci = $this->quantidadeFibonacci($jogo);
        $maiorSequencia = $this->maiorSequencia($jogo);
        $faixas = $this->contagemFaixas($jogo);
        $repetidasRecentes = $this->repeticoesComUltimosConcursos($jogo, $metricas['ultimos_5_concursos']);

        $bonus = 0;

        $bonus += $this->pontuarProximidadeIntervalo($pares, $metricas['intervalo_pares'], 8);
        $bonus += $this->pontuarProximidadeIntervalo($soma, $metricas['intervalo_soma'], 12);
        $bonus += $this->pontuarProximidadeIntervalo($repetidasUltimo, $metricas['intervalo_repetidas'], 7);
        $bonus += $this->pontuarProximidadeIntervalo($moldura, $metricas['intervalo_moldura'], 6);
        $bonus += $this->pontuarProximidadeIntervalo($centro, $metricas['intervalo_centro'], 5);
        $bonus += $this->pontuarProximidadeIntervalo($primos, $metricas['intervalo_primos'], 5);
        $bonus += $this->pontuarProximidadeIntervalo($fibonacci, $metricas['intervalo_fibonacci'], 3);

        if ($quentes >= 4 && $quentes <= 7) {
            $bonus += 7;
        } elseif ($quentes === 3 || $quentes === 8) {
            $bonus += 4;
        }

        if ($atrasadas >= 2 && $atrasadas <= 5) {
            $bonus += 7;
        } elseif ($atrasadas === 6) {
            $bonus += 3;
        }

        if ($this->quadrantesValidos($jogo)) {
            $bonus += 5;
        }

        if ($this->distribuicaoLinhasColunasValida($jogo)) {
            $bonus += 5;
        }

        foreach ($faixas as $indice => $qtd) {
            $bonus += $this->pontuarProximidadeIntervalo($qtd, $metricas['intervalos_faixas'][$indice], 1.8);
        }

        $penalidade = 0;

        if ($maiorSequencia === $metricas['maior_sequencia_maxima']) {
            $penalidade += 2.5;
        } elseif ($maiorSequencia > $metricas['maior_sequencia_maxima']) {
            $penalidade += 14;
        }

        if ($this->temLinhaOuColunaCompleta($jogo)) {
            $penalidade += 15;
        }

        if ($this->temDiagonalCompleta($jogo)) {
            $penalidade += 8;
        }

        if ($repetidasRecentes['maxima'] >= 11) {
            $penalidade += 8;
        }

        if ($this->temPadraoSimetricoExcessivo($jogo)) {
            $penalidade += 7;
        }

        return round($scoreBase + $bonus - $penalidade, 2);
    }

    private function resumoJogo(array $jogo, array $metricas, array $ultimoConcurso): array
    {
        $pares = $this->quantidadePares($jogo);
        $impares = $this->quantidadeDezenasJogo - $pares;
        $repetidasRecentes = $this->repeticoesComUltimosConcursos($jogo, $metricas['ultimos_5_concursos']);

        return [
            'pares' => $pares,
            'impares' => $impares,
            'soma' => array_sum($jogo),
            'repetidas_ultimo_concurso' => count(array_intersect($jogo, $ultimoConcurso)),
            'media_repetidas_ultimos_5' => $repetidasRecentes['media'],
            'max_repetidas_ultimos_5' => $repetidasRecentes['maxima'],
            'quentes' => count(array_intersect($jogo, $metricas['top_quentes'])),
            'atrasadas' => count(array_intersect($jogo, $metricas['top_atrasadas'])),
            'moldura' => $this->quantidadeNaMoldura($jogo),
            'centro' => $this->quantidadeNoCentro($jogo),
            'primos' => $this->quantidadePrimos($jogo),
            'fibonacci' => $this->quantidadeFibonacci($jogo),
            'maior_sequencia' => $this->maiorSequencia($jogo),
            'faixas' => $this->contagemFaixas($jogo),
            'top_quentes' => $metricas['top_quentes'],
            'top_atrasadas' => $metricas['top_atrasadas'],
        ];
    }

    private function quantidadeNaMoldura(array $jogo): int
    {
        return count(array_intersect($jogo, $this->moldura));
    }

    private function quantidadeNoCentro(array $jogo): int
    {
        return count(array_intersect($jogo, $this->centro));
    }

    private function quantidadePrimos(array $jogo): int
    {
        return count(array_intersect($jogo, $this->primos));
    }

    private function quantidadeFibonacci(array $jogo): int
    {
        return count(array_intersect($jogo, $this->fibonacci));
    }

    private function quantidadePares(array $jogo): int
    {
        return count(array_filter($jogo, fn ($n) => $n % 2 === 0));
    }

    private function contagemFaixas(array $jogo): array
    {
        return [
            count(array_filter($jogo, fn ($n) => $n >= 1 && $n <= 5)),
            count(array_filter($jogo, fn ($n) => $n >= 6 && $n <= 10)),
            count(array_filter($jogo, fn ($n) => $n >= 11 && $n <= 15)),
            count(array_filter($jogo, fn ($n) => $n >= 16 && $n <= 20)),
            count(array_filter($jogo, fn ($n) => $n >= 21 && $n <= 25)),
        ];
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

    private function distribuicaoLinhasColunasValida(array $jogo): bool
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
            $qtd = count(array_intersect($jogo, $linha));
            if ($qtd < 1 || $qtd > 4) {
                return false;
            }
        }

        foreach ($colunas as $coluna) {
            $qtd = count(array_intersect($jogo, $coluna));
            if ($qtd < 1 || $qtd > 4) {
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

    private function temDiagonalCompleta(array $jogo): bool
    {
        $diagonais = [
            [1, 7, 13, 19, 25],
            [5, 9, 13, 17, 21],
        ];

        foreach ($diagonais as $diagonal) {
            if (count(array_intersect($jogo, $diagonal)) === 5) {
                return true;
            }
        }

        return false;
    }

    private function temPadraoSimetricoExcessivo(array $jogo): bool
    {
        $paresSimetricos = [
            [1, 25], [2, 24], [3, 23], [4, 22], [5, 21],
            [6, 20], [7, 19], [8, 18], [9, 17], [10, 16],
            [11, 15], [12, 14],
        ];

        $contador = 0;

        foreach ($paresSimetricos as [$a, $b]) {
            if (in_array($a, $jogo, true) && in_array($b, $jogo, true)) {
                $contador++;
            }
        }

        return $contador >= 6;
    }

    private function repeticoesComUltimosConcursos(array $jogo, array $ultimosConcursos): array
    {
        if (empty($ultimosConcursos)) {
            return [
                'media' => 0,
                'maxima' => 0,
            ];
        }

        $repeticoes = array_map(fn ($concurso) => count(array_intersect($jogo, $concurso)), $ultimosConcursos);

        return [
            'media' => round(array_sum($repeticoes) / count($repeticoes), 2),
            'maxima' => max($repeticoes),
        ];
    }

    private function intervaloHistorico(Collection $valores, float $percentilMin, float $percentilMax, int $limiteMinimo, int $limiteMaximo): array
    {
        $ordenados = $valores->sort()->values();

        if ($ordenados->isEmpty()) {
            return ['min' => $limiteMinimo, 'max' => $limiteMaximo];
        }

        $indiceMin = max((int) floor(($ordenados->count() - 1) * $percentilMin), 0);
        $indiceMax = min((int) ceil(($ordenados->count() - 1) * $percentilMax), $ordenados->count() - 1);

        $min = (int) $ordenados[$indiceMin];
        $max = (int) $ordenados[$indiceMax];

        $min = max($limiteMinimo, $min);
        $max = min($limiteMaximo, $max);

        if ($min > $max) {
            $min = $limiteMinimo;
            $max = $limiteMaximo;
        }

        return ['min' => $min, 'max' => $max];
    }

    private function estaNoIntervalo(int $valor, array $intervalo): bool
    {
        return $valor >= $intervalo['min'] && $valor <= $intervalo['max'];
    }

    private function pontuarProximidadeIntervalo(int $valor, array $intervalo, float $pesoMaximo): float
    {
        if ($this->estaNoIntervalo($valor, $intervalo)) {
            $centro = ($intervalo['min'] + $intervalo['max']) / 2;
            $distancia = abs($valor - $centro);
            $amplitude = max((($intervalo['max'] - $intervalo['min']) / 2), 1);

            return round(max(0, $pesoMaximo - (($distancia / $amplitude) * ($pesoMaximo * 0.45))), 2);
        }

        $distanciaFora = min(abs($valor - $intervalo['min']), abs($valor - $intervalo['max']));

        return round(max(0, $pesoMaximo - ($distanciaFora * 1.5)), 2);
    }

    private function selecionarTopDiversificado(array $top): array
    {
        $selecionados = [];

        foreach ($top as $candidato) {
            if (empty($selecionados)) {
                $selecionados[] = $candidato;
                continue;
            }

            $similar = false;

            foreach ($selecionados as $selecionado) {
                $intersecao = count(array_intersect($candidato['dezenas'], $selecionado['dezenas']));
                if ($intersecao >= 12) {
                    $similar = true;
                    break;
                }
            }

            if (! $similar) {
                $selecionados[] = $candidato;
            }
        }

        return $selecionados;
    }
}