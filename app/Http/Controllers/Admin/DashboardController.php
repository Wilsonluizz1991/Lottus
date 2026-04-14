<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LottusPedido;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $hoje = now()->startOfDay();
        $inicioPeriodo = now()->subDays(29)->startOfDay();
        $fimPeriodo = now()->endOfDay();

        $pedidosPagosQuery = LottusPedido::query()->where('status', 'pago');

        $faturamentoBrutoTotal = (float) $pedidosPagosQuery->clone()
            ->selectRaw('COALESCE(SUM(CASE WHEN valor_original > 0 THEN valor_original ELSE subtotal END), 0) as total')
            ->value('total');

        $descontoTotal = (float) $pedidosPagosQuery->clone()
            ->sum('desconto');

        $faturamentoLiquidoTotal = (float) $pedidosPagosQuery->clone()
            ->sum('valor');

        $totalPedidos = LottusPedido::count();
        $totalPedidosPagos = $pedidosPagosQuery->clone()->count();
        $totalPedidosPendentes = LottusPedido::where('status', 'aguardando_pagamento')->count();
        $totalPedidosHoje = LottusPedido::whereDate('created_at', $hoje)->count();
        $pedidosPagosHoje = $pedidosPagosQuery->clone()->whereDate('paid_at', $hoje)->count();
        $pedidosComCupom = $pedidosPagosQuery->clone()->whereNotNull('cupom_id')->count();

        $ticketMedio = $totalPedidosPagos > 0
            ? round($faturamentoLiquidoTotal / $totalPedidosPagos, 2)
            : 0;

        $percentualUsoCupom = $totalPedidosPagos > 0
            ? round(($pedidosComCupom / $totalPedidosPagos) * 100, 2)
            : 0;

        $dadosPorDia = LottusPedido::query()
            ->where('status', 'pago')
            ->whereBetween('paid_at', [$inicioPeriodo, $fimPeriodo])
            ->selectRaw('DATE(paid_at) as dia')
            ->selectRaw('COUNT(*) as total_pedidos')
            ->selectRaw('COALESCE(SUM(CASE WHEN valor_original > 0 THEN valor_original ELSE subtotal END), 0) as bruto')
            ->selectRaw('COALESCE(SUM(desconto), 0) as desconto')
            ->selectRaw('COALESCE(SUM(valor), 0) as liquido')
            ->groupBy(DB::raw('DATE(paid_at)'))
            ->orderBy('dia')
            ->get()
            ->keyBy('dia');

        $labels = [];
        $serieBruto = [];
        $serieDesconto = [];
        $serieLiquido = [];
        $seriePedidos = [];

        foreach (CarbonPeriod::create($inicioPeriodo, $fimPeriodo) as $data) {
            $dia = $data->format('Y-m-d');
            $linha = $dadosPorDia->get($dia);

            $labels[] = $data->format('d/m');
            $serieBruto[] = (float) ($linha->bruto ?? 0);
            $serieDesconto[] = (float) ($linha->desconto ?? 0);
            $serieLiquido[] = (float) ($linha->liquido ?? 0);
            $seriePedidos[] = (int) ($linha->total_pedidos ?? 0);
        }

        $ultimosPedidos = LottusPedido::with('cupom')
            ->latest()
            ->limit(10)
            ->get();

        return view('admin.dashboard', [
            'faturamentoBrutoTotal' => $faturamentoBrutoTotal,
            'descontoTotal' => $descontoTotal,
            'faturamentoLiquidoTotal' => $faturamentoLiquidoTotal,
            'totalPedidos' => $totalPedidos,
            'totalPedidosPagos' => $totalPedidosPagos,
            'totalPedidosPendentes' => $totalPedidosPendentes,
            'totalPedidosHoje' => $totalPedidosHoje,
            'pedidosPagosHoje' => $pedidosPagosHoje,
            'ticketMedio' => $ticketMedio,
            'pedidosComCupom' => $pedidosComCupom,
            'percentualUsoCupom' => $percentualUsoCupom,
            'labels' => $labels,
            'serieBruto' => $serieBruto,
            'serieDesconto' => $serieDesconto,
            'serieLiquido' => $serieLiquido,
            'seriePedidos' => $seriePedidos,
            'ultimosPedidos' => $ultimosPedidos,
        ]);
    }
}