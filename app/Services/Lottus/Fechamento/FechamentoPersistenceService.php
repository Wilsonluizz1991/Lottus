<?php

namespace App\Services\Lottus\Fechamento;

use App\Models\LotofacilConcurso;
use App\Models\LottusPedido;
use App\Services\CupomService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FechamentoPersistenceService
{
    public function __construct(
        private readonly CupomService $cupomService
    ) {
    }

    public function store(
        string $email,
        LotofacilConcurso $concursoBase,
        int $quantidadeDezenas,
        array $dezenasBase,
        array $jogos,
        ?string $cupom = null,
        ?string $commercialSeed = null
    ): array {
        $dezenasBase = array_values(array_unique(array_map('intval', $dezenasBase)));
        sort($dezenasBase);

        $jogosNormalizados = $this->normalizePortfolioGames($jogos);

        if (empty($jogosNormalizados)) {
            throw new \Exception('Nenhum jogo válido foi encontrado para persistir o fechamento.');
        }

        $portfolioFingerprint = $this->portfolioFingerprint($jogosNormalizados);

        if ($this->hasIdenticalCommercialPortfolio(
            concursoBase: $concursoBase,
            quantidadeDezenas: $quantidadeDezenas,
            quantidadeJogos: count($jogosNormalizados),
            portfolioFingerprint: $portfolioFingerprint
        )) {
            throw new \Exception('Bloqueio anti-duplicidade: este fechamento é idêntico a um fechamento já existente. Gere novamente.');
        }

        $tokenLote = (string) Str::uuid();

        $valorOriginal = (float) config("lottus_fechamento.prices.{$quantidadeDezenas}", 0);

        if ($valorOriginal <= 0) {
            throw new \Exception('Valor do fechamento não configurado.');
        }

        $desconto = 0.0;
        $valorFinal = $valorOriginal;
        $cupomModel = null;
        $cupomAplicado = null;
        $descricaoCupom = null;

        if (! empty($cupom)) {
            $resultadoCupom = $this->cupomService->validarCupom(
                $cupom,
                $valorOriginal,
                $email
            );

            if (! ($resultadoCupom['valido'] ?? false)) {
                throw new \Exception($resultadoCupom['mensagem'] ?? 'Cupom inválido para este fechamento.');
            }

            $cupomModel = $resultadoCupom['cupom'] ?? null;
            $cupomAplicado = $cupomModel->codigo ?? $cupom;
            $descricaoCupom = $resultadoCupom['descricao'] ?? null;
            $desconto = (float) ($resultadoCupom['desconto'] ?? 0);
            $valorFinal = (float) ($resultadoCupom['valor_final'] ?? $valorOriginal);
        }

        $valorFinal = max(0, round($valorFinal, 2));
        $desconto = max(0, round($desconto, 2));

        $pedidoPagoPorCupom = $valorFinal <= 0;

        $pedidoData = [
            'token' => (string) Str::uuid(),
            'email' => $email,
            'quantidade' => count($jogosNormalizados),
            'concurso_base_id' => $concursoBase->id,
            'valor' => $valorFinal,
            'jogo' => $jogosNormalizados,
            'analise' => [
                'tipo' => 'fechamento_inteligente',
                'produto' => config('lottus_fechamento.product.name', 'Fechamento Inteligente Lottus'),
                'engine_version' => config('lottus_fechamento.product.engine_version', 'fechamento-v1'),
                'token_lote' => $tokenLote,
                'portfolio_fingerprint' => $portfolioFingerprint,
                'commercial_seed_hash' => $commercialSeed ? hash('sha256', $commercialSeed) : null,
                'quantidade_dezenas' => $quantidadeDezenas,
                'dezenas_base' => $dezenasBase,
                'quantidade_jogos' => count($jogosNormalizados),
                'scores' => collect($jogos)->pluck('score')->values()->toArray(),
                'cupom' => $cupomAplicado,
                'cupom_descricao' => $descricaoCupom,
                'valor_original' => $valorOriginal,
                'desconto' => $desconto,
                'valor_final' => $valorFinal,
            ],
            'status' => $pedidoPagoPorCupom ? 'pago' : 'aguardando_pagamento',
            'gateway' => $pedidoPagoPorCupom ? 'cupom' : 'mercado_pago',
            'external_reference' => (string) Str::uuid(),
            'expires_at' => $pedidoPagoPorCupom ? null : now()->addMinutes(30),
            'subtotal' => $valorOriginal,
            'desconto' => $desconto,
            'valor_original' => $valorOriginal,
        ];

        if (Schema::hasColumn('lottus_pedidos', 'payment_status')) {
            $pedidoData['payment_status'] = $pedidoPagoPorCupom ? 'approved' : null;
        }

        if (Schema::hasColumn('lottus_pedidos', 'paid_at')) {
            $pedidoData['paid_at'] = $pedidoPagoPorCupom ? now() : null;
        }

        if (Schema::hasColumn('lottus_pedidos', 'valor_final')) {
            $pedidoData['valor_final'] = $valorFinal;
        }

        if (Schema::hasColumn('lottus_pedidos', 'cupom')) {
            $pedidoData['cupom'] = $cupomAplicado;
        }

        if (Schema::hasColumn('lottus_pedidos', 'cupom_id')) {
            $pedidoData['cupom_id'] = $cupomModel?->id;
        }

        if (Schema::hasColumn('lottus_pedidos', 'cupom_codigo')) {
            $pedidoData['cupom_codigo'] = $cupomAplicado;
        }

        $pedido = LottusPedido::query()->create($pedidoData);

        if ($cupomModel !== null) {
            $this->cupomService->registrarUso($cupomModel);
        }

        return [
            'token_lote' => $tokenLote,
            'pedido' => $pedido,
            'pedido_token' => $pedido->token,
            'email' => $email,
            'quantidade_dezenas' => $quantidadeDezenas,
            'dezenas_base' => $dezenasBase,
            'quantidade_jogos' => count($jogosNormalizados),
            'portfolio_fingerprint' => $portfolioFingerprint,
            'valor' => $valorFinal,
            'valor_original' => $valorOriginal,
            'desconto' => $desconto,
            'cupom' => $cupomAplicado,
            'jogos' => $jogosNormalizados,
        ];
    }

    public function hasIdenticalCommercialPortfolio(
        LotofacilConcurso $concursoBase,
        int $quantidadeDezenas,
        int $quantidadeJogos,
        string $portfolioFingerprint
    ): bool {
        $recentPedidos = LottusPedido::query()
            ->where('concurso_base_id', $concursoBase->id)
            ->where('quantidade', $quantidadeJogos)
            ->latest('id')
            ->limit((int) config('lottus_fechamento.commercial_duplicate_scan_limit', 500))
            ->get(['id', 'jogo', 'analise']);

        foreach ($recentPedidos as $pedido) {
            $analise = is_array($pedido->analise) ? $pedido->analise : [];

            if (($analise['tipo'] ?? null) !== 'fechamento_inteligente') {
                continue;
            }

            if ((int) ($analise['quantidade_dezenas'] ?? 0) !== $quantidadeDezenas) {
                continue;
            }

            if (($analise['portfolio_fingerprint'] ?? null) === $portfolioFingerprint) {
                return true;
            }

            if ($this->portfolioFingerprint((array) $pedido->jogo) === $portfolioFingerprint) {
                return true;
            }
        }

        return false;
    }

    public function portfolioFingerprint(array $jogos): string
    {
        $keys = [];

        foreach ($this->normalizePortfolioGames($jogos) as $jogo) {
            $keys[] = implode('-', $jogo);
        }

        sort($keys);

        return hash('sha256', implode('|', $keys));
    }

    private function normalizePortfolioGames(array $jogos): array
    {
        $jogosNormalizados = [];

        foreach ($jogos as $jogo) {
            $dezenas = $jogo['dezenas'] ?? $jogo;

            $dezenas = array_values(array_unique(array_map('intval', (array) $dezenas)));
            sort($dezenas);

            if (count($dezenas) !== 15) {
                continue;
            }

            $jogosNormalizados[] = $dezenas;
        }

        return $jogosNormalizados;
    }
}
