@extends('admin.layout')

@section('admin_page')
<div class="admin-dashboard-page">
    <div class="dashboard-hero mb-4">
        <div class="dashboard-hero-content">
            <div>
                <span class="dashboard-kicker">Painel executivo</span>
                <h1 class="dashboard-title mb-2">Dashboard administrativo</h1>
                <p class="dashboard-subtitle mb-0">
                    Uma visão mais elegante e estratégica da performance do Lottus, com foco em receita,
                    descontos, conversão comercial e movimentação recente da plataforma.
                </p>
            </div>

            <div class="dashboard-hero-actions">
                <a href="{{ route('admin.pedidos.index') }}" class="btn btn-outline-secondary rounded-4 px-4">
                    Ver pedidos
                </a>
                <a href="{{ route('admin.cupons.create') }}" class="btn btn-primary rounded-4 px-4">
                    Criar cupom
                </a>
            </div>
        </div>

        <div class="dashboard-hero-grid mt-4">
            <div class="hero-mini-card">
                <div class="hero-mini-label">Faturamento líquido</div>
                <div class="hero-mini-value text-primary">R$ {{ number_format($faturamentoLiquidoTotal, 2, ',', '.') }}</div>
                <div class="hero-mini-helper">Receita efetiva da operação</div>
            </div>

            <div class="hero-mini-card">
                <div class="hero-mini-label">Desconto concedido</div>
                <div class="hero-mini-value text-success">R$ {{ number_format($descontoTotal, 2, ',', '.') }}</div>
                <div class="hero-mini-helper">Impacto comercial dos cupons</div>
            </div>

            <div class="hero-mini-card">
                <div class="hero-mini-label">Pedidos pagos</div>
                <div class="hero-mini-value">{{ $totalPedidosPagos }}</div>
                <div class="hero-mini-helper">Pedidos aprovados até agora</div>
            </div>

            <div class="hero-mini-card">
                <div class="hero-mini-label">Ticket médio</div>
                <div class="hero-mini-value">R$ {{ number_format($ticketMedio, 2, ',', '.') }}</div>
                <div class="hero-mini-helper">Valor médio por pedido pago</div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6 col-xl-3">
            <div class="dashboard-stat-card stat-card-bruto h-100">
                <div class="dashboard-stat-top">
                    <div class="dashboard-stat-icon">R$</div>
                    <div class="dashboard-stat-badge">Bruto</div>
                </div>
                <div class="dashboard-stat-label">Faturamento bruto</div>
                <div class="dashboard-stat-value">R$ {{ number_format($faturamentoBrutoTotal, 2, ',', '.') }}</div>
                <div class="dashboard-stat-helper">Valor cheio gerado antes de descontos</div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="dashboard-stat-card stat-card-desconto h-100">
                <div class="dashboard-stat-top">
                    <div class="dashboard-stat-icon">%</div>
                    <div class="dashboard-stat-badge">Promoções</div>
                </div>
                <div class="dashboard-stat-label">Descontos concedidos</div>
                <div class="dashboard-stat-value text-success">R$ {{ number_format($descontoTotal, 2, ',', '.') }}</div>
                <div class="dashboard-stat-helper">Valor total abatido por cupons</div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="dashboard-stat-card stat-card-liquido h-100">
                <div class="dashboard-stat-top">
                    <div class="dashboard-stat-icon">+</div>
                    <div class="dashboard-stat-badge">Receita</div>
                </div>
                <div class="dashboard-stat-label">Faturamento líquido</div>
                <div class="dashboard-stat-value text-primary">R$ {{ number_format($faturamentoLiquidoTotal, 2, ',', '.') }}</div>
                <div class="dashboard-stat-helper">Receita consolidada após descontos</div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="dashboard-stat-card stat-card-ticket h-100">
                <div class="dashboard-stat-top">
                    <div class="dashboard-stat-icon">TM</div>
                    <div class="dashboard-stat-badge">Eficiência</div>
                </div>
                <div class="dashboard-stat-label">Ticket médio</div>
                <div class="dashboard-stat-value">R$ {{ number_format($ticketMedio, 2, ',', '.') }}</div>
                <div class="dashboard-stat-helper">Média por pedido com pagamento aprovado</div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6 col-xl-3">
            <div class="dashboard-metric-card h-100">
                <div class="dashboard-metric-label">Total de pedidos</div>
                <div class="dashboard-metric-value">{{ $totalPedidos }}</div>
                <div class="dashboard-metric-meta">Base geral do sistema</div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="dashboard-metric-card h-100">
                <div class="dashboard-metric-label">Pedidos pagos</div>
                <div class="dashboard-metric-value text-success">{{ $totalPedidosPagos }}</div>
                <div class="dashboard-metric-meta">Conversões concluídas</div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="dashboard-metric-card h-100">
                <div class="dashboard-metric-label">Pendentes</div>
                <div class="dashboard-metric-value text-warning">{{ $totalPedidosPendentes }}</div>
                <div class="dashboard-metric-meta">Aguardando pagamento</div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="dashboard-metric-card h-100">
                <div class="dashboard-metric-label">Uso de cupom</div>
                <div class="dashboard-metric-value">{{ number_format($percentualUsoCupom, 2, ',', '.') }}%</div>
                <div class="dashboard-metric-meta">{{ $pedidosComCupom }} pedidos pagos com cupom</div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-8">
            <div class="card border-0 shadow-sm dashboard-chart-card h-100">
                <div class="card-body p-4 p-xl-5">
                    <div class="dashboard-section-header mb-4">
                        <div>
                            <span class="dashboard-section-kicker">Análise temporal</span>
                            <h2 class="dashboard-section-title mb-1">Vendas dos últimos 30 dias</h2>
                            <p class="dashboard-section-text mb-0">
                                Evolução diária do bruto, desconto e líquido para leitura rápida da performance comercial.
                            </p>
                        </div>
                    </div>

                    <div class="chart-shell">
                        <canvas id="salesChart" height="110"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="dashboard-side-stack h-100">
                <div class="card border-0 shadow-sm dashboard-today-card">
                    <div class="card-body p-4">
                        <div class="dashboard-section-header mb-3">
                            <div>
                                <span class="dashboard-section-kicker">Hoje</span>
                                <h2 class="dashboard-section-title mb-1">Resumo diário</h2>
                                <p class="dashboard-section-text mb-0">
                                    O que aconteceu hoje na operação.
                                </p>
                            </div>
                        </div>

                        <div class="today-card-grid">
                            <div class="today-mini-box">
                                <div class="today-mini-label">Criados hoje</div>
                                <div class="today-mini-value">{{ $totalPedidosHoje }}</div>
                            </div>

                            <div class="today-mini-box">
                                <div class="today-mini-label">Pagos hoje</div>
                                <div class="today-mini-value text-success">{{ $pedidosPagosHoje }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm dashboard-insights-card mt-4">
                    <div class="card-body p-4">
                        <div class="dashboard-section-header mb-3">
                            <div>
                                <span class="dashboard-section-kicker">Insights</span>
                                <h2 class="dashboard-section-title mb-1">Leitura rápida</h2>
                            </div>
                        </div>

                        <div class="insight-list">
                            <div class="insight-item">
                                <div class="insight-bullet"></div>
                                <div class="insight-content">
                                    <div class="insight-title">Receita líquida consolidada</div>
                                    <div class="insight-text">
                                        R$ {{ number_format($faturamentoLiquidoTotal, 2, ',', '.') }} acumulados em pedidos pagos.
                                    </div>
                                </div>
                            </div>

                            <div class="insight-item">
                                <div class="insight-bullet success"></div>
                                <div class="insight-content">
                                    <div class="insight-title">Participação de cupons</div>
                                    <div class="insight-text">
                                        {{ $pedidosComCupom }} pedidos pagos utilizaram cupom, representando {{ number_format($percentualUsoCupom, 2, ',', '.') }}%.
                                    </div>
                                </div>
                            </div>

                            <div class="insight-item">
                                <div class="insight-bullet warning"></div>
                                <div class="insight-content">
                                    <div class="insight-title">Fila de conversão</div>
                                    <div class="insight-text">
                                        {{ $totalPedidosPendentes }} pedidos ainda estão aguardando confirmação de pagamento.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="dashboard-quick-actions mt-4">
                            <a href="{{ route('admin.pedidos.index') }}" class="btn btn-outline-primary rounded-4">
                                Gerenciar pedidos
                            </a>
                            <a href="{{ route('admin.cupons.index') }}" class="btn btn-primary rounded-4">
                                Gerenciar cupons
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm dashboard-orders-card">
        <div class="card-body p-0">
            <div class="dashboard-orders-header">
                <div>
                    <span class="dashboard-section-kicker">Atividade recente</span>
                    <h2 class="dashboard-section-title mb-1">Últimos pedidos</h2>
                    <p class="dashboard-section-text mb-0">
                        Os pedidos mais recentes da plataforma em uma visualização mais refinada.
                    </p>
                </div>

                <a href="{{ route('admin.pedidos.index') }}" class="btn btn-outline-secondary rounded-4 px-4">
                    Ver todos
                </a>
            </div>

            <div class="table-responsive">
                <table class="table dashboard-orders-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Pedido</th>
                            <th>Cliente</th>
                            <th>Status</th>
                            <th>Financeiro</th>
                            <th>Cupom</th>
                            <th>Criado em</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($ultimosPedidos as $pedido)
                            <tr>
                                <td>
                                    <div class="order-token">{{ \Illuminate\Support\Str::limit($pedido->token, 14, '...') }}</div>
                                    <div class="order-submeta">{{ $pedido->quantidade }} {{ $pedido->quantidade === 1 ? 'jogo' : 'jogos' }}</div>
                                </td>

                                <td>
                                    <div class="order-email">{{ $pedido->email }}</div>
                                    <div class="order-submeta">{{ $pedido->gateway }}</div>
                                </td>

                                <td>
                                    @if($pedido->status === 'pago')
                                        <span class="dashboard-status-badge status-success">Pago</span>
                                    @elseif($pedido->status === 'aguardando_pagamento')
                                        <span class="dashboard-status-badge status-warning">Aguardando</span>
                                    @elseif($pedido->status === 'cancelado')
                                        <span class="dashboard-status-badge status-danger">Cancelado</span>
                                    @elseif($pedido->status === 'expirado')
                                        <span class="dashboard-status-badge status-secondary">Expirado</span>
                                    @else
                                        <span class="dashboard-status-badge status-dark">{{ $pedido->status }}</span>
                                    @endif
                                </td>

                                <td>
                                    <div class="order-value">R$ {{ number_format($pedido->valor, 2, ',', '.') }}</div>
                                    <div class="order-submeta">
                                        Desconto: R$ {{ number_format($pedido->desconto, 2, ',', '.') }}
                                    </div>
                                </td>

                                <td>
                                    <span class="order-coupon-chip">
                                        {{ $pedido->cupom?->codigo ?? 'Sem cupom' }}
                                    </span>
                                </td>

                                <td>
                                    <div class="order-date">{{ $pedido->created_at?->format('d/m/Y') }}</div>
                                    <div class="order-submeta">{{ $pedido->created_at?->format('H:i') }}</div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="dashboard-empty-state">
                                        <div class="dashboard-empty-icon">L</div>
                                        <div class="dashboard-empty-title">Nenhum pedido encontrado</div>
                                        <div class="dashboard-empty-text">
                                            Assim que houver movimentação recente, ela aparecerá aqui.
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
    .admin-dashboard-page {
        color: #0f172a;
    }

    .dashboard-hero {
        position: relative;
        overflow: hidden;
        border-radius: 32px;
        padding: 2rem;
        background:
            radial-gradient(circle at top left, rgba(59, 130, 246, 0.16), transparent 30%),
            radial-gradient(circle at bottom right, rgba(99, 102, 241, 0.14), transparent 28%),
            linear-gradient(135deg, #ffffff 0%, #f8fbff 52%, #f3f8ff 100%);
        border: 1px solid #e2e8f0;
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.08);
    }

    .dashboard-kicker,
    .dashboard-section-kicker {
        display: inline-flex;
        align-items: center;
        padding: 0.45rem 0.85rem;
        border-radius: 999px;
        background: rgba(37, 99, 235, 0.10);
        color: #1d4ed8;
        font-size: 0.78rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }

    .dashboard-title {
        font-size: 2.2rem;
        font-weight: 800;
        line-height: 1.02;
        letter-spacing: -0.03em;
        color: #0f172a;
        margin-top: 0.9rem;
    }

    .dashboard-subtitle,
    .dashboard-section-text {
        color: #64748b;
        max-width: 820px;
        line-height: 1.65;
    }

    .dashboard-hero-content {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1.5rem;
    }

    .dashboard-hero-actions {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .dashboard-hero-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 1rem;
    }

    .hero-mini-card {
        padding: 1.15rem 1.2rem;
        border-radius: 24px;
        background: rgba(255, 255, 255, 0.72);
        border: 1px solid rgba(226, 232, 240, 0.9);
        backdrop-filter: blur(10px);
        box-shadow: 0 14px 36px rgba(15, 23, 42, 0.05);
    }

    .hero-mini-label,
    .dashboard-stat-label,
    .dashboard-metric-label {
        font-size: 0.8rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #64748b;
        margin-bottom: 0.6rem;
    }

    .hero-mini-value {
        font-size: 1.7rem;
        line-height: 1;
        font-weight: 800;
        color: #0f172a;
        margin-bottom: 0.45rem;
    }

    .hero-mini-helper,
    .dashboard-stat-helper,
    .dashboard-metric-meta {
        color: #64748b;
        font-size: 0.92rem;
        line-height: 1.5;
    }

    .dashboard-stat-card {
        border-radius: 28px;
        padding: 1.45rem;
        background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        border: 1px solid #e2e8f0;
        box-shadow: 0 18px 46px rgba(15, 23, 42, 0.06);
    }

    .dashboard-stat-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 1rem;
    }

    .dashboard-stat-icon {
        width: 52px;
        height: 52px;
        border-radius: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.95rem;
        font-weight: 900;
        color: #0f172a;
        background: #eff6ff;
    }

    .dashboard-stat-badge {
        padding: 0.4rem 0.75rem;
        border-radius: 999px;
        font-size: 0.76rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        background: #f8fafc;
        color: #475569;
        border: 1px solid #e2e8f0;
    }

    .dashboard-stat-value,
    .dashboard-metric-value {
        font-size: 2rem;
        font-weight: 800;
        line-height: 1;
        color: #0f172a;
        margin-bottom: 0.45rem;
    }

    .stat-card-bruto .dashboard-stat-icon {
        background: rgba(15, 23, 42, 0.06);
    }

    .stat-card-desconto .dashboard-stat-icon {
        background: rgba(34, 197, 94, 0.12);
        color: #15803d;
    }

    .stat-card-liquido .dashboard-stat-icon {
        background: rgba(37, 99, 235, 0.12);
        color: #1d4ed8;
    }

    .stat-card-ticket .dashboard-stat-icon {
        background: rgba(99, 102, 241, 0.12);
        color: #4338ca;
    }

    .dashboard-metric-card {
        border-radius: 24px;
        padding: 1.3rem 1.35rem;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        box-shadow: 0 14px 38px rgba(15, 23, 42, 0.05);
    }

    .dashboard-chart-card,
    .dashboard-today-card,
    .dashboard-insights-card,
    .dashboard-orders-card {
        border-radius: 30px;
        overflow: hidden;
    }

    .dashboard-section-title {
        font-size: 1.45rem;
        font-weight: 800;
        color: #0f172a;
    }

    .chart-shell {
        padding: 1rem;
        border-radius: 24px;
        background: linear-gradient(180deg, #fcfdff 0%, #f7faff 100%);
        border: 1px solid #e7eef8;
    }

    .today-card-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }

    .today-mini-box {
        border-radius: 22px;
        padding: 1.15rem;
        background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        border: 1px solid #e2e8f0;
    }

    .today-mini-label {
        color: #64748b;
        font-size: 0.84rem;
        font-weight: 700;
        margin-bottom: 0.45rem;
    }

    .today-mini-value {
        font-size: 1.8rem;
        font-weight: 800;
        line-height: 1;
        color: #0f172a;
    }

    .insight-list {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .insight-item {
        display: flex;
        align-items: flex-start;
        gap: 0.9rem;
    }

    .insight-bullet {
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: #2563eb;
        margin-top: 0.45rem;
        flex-shrink: 0;
    }

    .insight-bullet.success {
        background: #16a34a;
    }

    .insight-bullet.warning {
        background: #d97706;
    }

    .insight-title {
        font-weight: 800;
        color: #0f172a;
        margin-bottom: 0.2rem;
    }

    .insight-text {
        color: #64748b;
        line-height: 1.6;
        font-size: 0.95rem;
    }

    .dashboard-quick-actions {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.75rem;
    }

    .dashboard-orders-header {
        padding: 1.5rem 1.5rem 1rem;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        border-bottom: 1px solid #eef2f7;
        background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
    }

    .dashboard-orders-table {
        --bs-table-bg: transparent;
    }

    .dashboard-orders-table thead th {
        background: #f8fafc;
        color: #64748b;
        font-size: 0.78rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        border-bottom: 1px solid #e9eef5;
        padding: 1rem 1.5rem;
        white-space: nowrap;
    }

    .dashboard-orders-table tbody td {
        padding: 1.15rem 1.5rem;
        border-color: #eef2f7;
        vertical-align: middle;
    }

    .dashboard-orders-table tbody tr:hover {
        background: #fbfdff;
    }

    .order-token,
    .order-email,
    .order-value,
    .order-date {
        font-weight: 800;
        color: #0f172a;
    }

    .order-submeta {
        color: #64748b;
        font-size: 0.9rem;
        margin-top: 0.2rem;
    }

    .order-coupon-chip {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.45rem 0.85rem;
        border-radius: 999px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        color: #334155;
        font-size: 0.86rem;
        font-weight: 700;
    }

    .dashboard-status-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 116px;
        padding: 0.5rem 0.85rem;
        border-radius: 999px;
        font-size: 0.82rem;
        font-weight: 800;
        letter-spacing: 0.01em;
    }

    .status-success {
        background: rgba(34, 197, 94, 0.14);
        color: #15803d;
    }

    .status-warning {
        background: rgba(245, 158, 11, 0.16);
        color: #a16207;
    }

    .status-danger {
        background: rgba(239, 68, 68, 0.14);
        color: #b91c1c;
    }

    .status-secondary {
        background: rgba(148, 163, 184, 0.18);
        color: #475569;
    }

    .status-dark {
        background: rgba(15, 23, 42, 0.10);
        color: #0f172a;
    }

    .dashboard-empty-state {
        text-align: center;
        padding: 3rem 1rem;
    }

    .dashboard-empty-icon {
        width: 72px;
        height: 72px;
        margin: 0 auto 1rem;
        border-radius: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #2563eb 0%, #4f46e5 100%);
        color: #fff;
        font-size: 2rem;
        font-weight: 900;
        box-shadow: 0 16px 36px rgba(37, 99, 235, 0.26);
    }

    .dashboard-empty-title {
        font-size: 1.2rem;
        font-weight: 800;
        color: #0f172a;
        margin-bottom: 0.35rem;
    }

    .dashboard-empty-text {
        color: #64748b;
        max-width: 420px;
        margin: 0 auto;
        line-height: 1.6;
    }

    @media (max-width: 1199px) {
        .dashboard-hero-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 991px) {
        .dashboard-hero-content,
        .dashboard-orders-header {
            flex-direction: column;
        }

        .dashboard-orders-table thead th,
        .dashboard-orders-table tbody td {
            padding-left: 1rem;
            padding-right: 1rem;
        }
    }

    @media (max-width: 767px) {
        .dashboard-hero {
            padding: 1.35rem;
        }

        .dashboard-title {
            font-size: 1.75rem;
        }

        .dashboard-hero-grid,
        .today-card-grid {
            grid-template-columns: 1fr;
        }

        .dashboard-stat-card,
        .dashboard-metric-card {
            padding: 1.2rem;
        }

        .dashboard-stat-value,
        .dashboard-metric-value,
        .hero-mini-value {
            font-size: 1.6rem;
        }
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const ctx = document.getElementById('salesChart');

        if (!ctx) {
            return;
        }

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: @json($labels),
                datasets: [
                    {
                        label: 'Bruto',
                        data: @json($serieBruto),
                        borderColor: '#0f172a',
                        backgroundColor: 'rgba(15, 23, 42, 0.08)',
                        borderWidth: 3,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        tension: 0.35,
                        fill: false
                    },
                    {
                        label: 'Desconto',
                        data: @json($serieDesconto),
                        borderColor: '#16a34a',
                        backgroundColor: 'rgba(22, 163, 74, 0.08)',
                        borderWidth: 3,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        tension: 0.35,
                        fill: false
                    },
                    {
                        label: 'Líquido',
                        data: @json($serieLiquido),
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.08)',
                        borderWidth: 3,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        tension: 0.35,
                        fill: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'start',
                        labels: {
                            usePointStyle: true,
                            boxWidth: 10,
                            color: '#334155',
                            font: {
                                size: 12,
                                weight: '700'
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleColor: '#ffffff',
                        bodyColor: '#e2e8f0',
                        padding: 12,
                        displayColors: true
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: '#64748b',
                            font: {
                                weight: '600'
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(148, 163, 184, 0.18)'
                        },
                        ticks: {
                            color: '#64748b',
                            font: {
                                weight: '600'
                            }
                        }
                    }
                }
            }
        });
    });
</script>
@endsection