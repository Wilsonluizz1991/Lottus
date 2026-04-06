<?php

namespace App\Services;

use App\Models\Cupom;
use App\Models\LottusPedido;
use Carbon\Carbon;

class CupomService
{
    public function normalizarCodigo(string $codigo): string
    {
        return strtoupper(trim($codigo));
    }

    public function buscarCupomPorCodigo(string $codigo): ?Cupom
    {
        $codigoNormalizado = $this->normalizarCodigo($codigo);

        return Cupom::where('codigo', $codigoNormalizado)->first();
    }

    public function validarCupom(string $codigo, float $subtotal, ?string $email = null): array
    {
        $cupom = $this->buscarCupomPorCodigo($codigo);

        if (! $cupom) {
            return $this->erro('Cupom não encontrado.');
        }

        if (! $cupom->ativo) {
            return $this->erro('Este cupom está inativo.');
        }

        $agora = Carbon::now();

        if ($cupom->inicio_em && $agora->lt($cupom->inicio_em)) {
            return $this->erro('Este cupom ainda não está disponível.');
        }

        if ($cupom->expira_em && $agora->gt($cupom->expira_em)) {
            return $this->erro('Este cupom expirou.');
        }

        if ($cupom->valor_minimo_pedido !== null && $subtotal < (float) $cupom->valor_minimo_pedido) {
            return $this->erro(
                'Este cupom exige pedido mínimo de R$ ' .
                number_format((float) $cupom->valor_minimo_pedido, 2, ',', '.')
            );
        }

        if ($cupom->limite_total_uso !== null && $cupom->total_usos >= $cupom->limite_total_uso) {
            return $this->erro('Este cupom já atingiu o limite máximo de usos.');
        }

        if ($email && $cupom->limite_uso_por_email !== null) {
            $usosDoEmail = LottusPedido::where('cupom_id', $cupom->id)
                ->where('email', $email)
                ->count();

            if ($usosDoEmail >= $cupom->limite_uso_por_email) {
                return $this->erro('Este cupom já atingiu o limite de uso para este e-mail.');
            }
        }

        $desconto = $this->calcularDesconto($cupom, $subtotal);
        $valorFinal = max(0, round($subtotal - $desconto, 2));

        return [
            'valido' => true,
            'mensagem' => 'Cupom aplicado com sucesso.',
            'cupom' => $cupom,
            'subtotal' => round($subtotal, 2),
            'desconto' => $desconto,
            'valor_final' => $valorFinal,
            'descricao' => $this->gerarDescricao($cupom),
        ];
    }

    public function calcularDesconto(Cupom $cupom, float $subtotal): float
    {
        $subtotal = round($subtotal, 2);

        if ($cupom->tipo_desconto === 'percentual') {
            $desconto = $subtotal * ((float) $cupom->valor_desconto / 100);
        } else {
            $desconto = (float) $cupom->valor_desconto;
        }

        return round(min($desconto, $subtotal), 2);
    }

    public function registrarUso(Cupom $cupom): void
    {
        $cupom->increment('total_usos');
    }

    private function gerarDescricao(Cupom $cupom): string
    {
        if ($cupom->tipo_desconto === 'percentual') {
            return rtrim(rtrim(number_format((float) $cupom->valor_desconto, 2, ',', '.'), '0'), ',') . '% OFF';
        }

        return 'R$ ' . number_format((float) $cupom->valor_desconto, 2, ',', '.') . ' OFF';
    }

    private function erro(string $mensagem): array
    {
        return [
            'valido' => false,
            'mensagem' => $mensagem,
        ];
    }
}