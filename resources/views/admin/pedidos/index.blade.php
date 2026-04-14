@extends('admin.layout')

@section('admin_page')
@php
    $statusAtual = $filtros['status'] ?? '';

    $totalPagina = $pedidos->count();
    $pagosPagina = $pedidos->where('status', 'pago')->count();
    $pendentesPagina = $pedidos->where('status', 'aguardando_pagamento')->count();
    $valorPagina = $pedidos->sum(fn ($pedido) => (float) $pedido->valor);
@endphp

<div class="pedidos-page">
    <div class="pedidos-topbar mb-4">
        <div>
            <span class="pedidos-kicker">Gestão comercial</span>
            <h1 class="pedidos-title mb-1">Pedidos</h1>
            <p class="pedidos-subtitle mb-0">
                Acompanhe vendas, pagamentos, descontos e uso de cupons em uma visão mais limpa e organizada.
            </p>
        </div>

        <div class="pedidos-topbar-actions">
            <a href="{{ route('admin.dashboard') }}" class="btn btn-outline-secondary rounded-4 px-4">
                Voltar ao dashboard
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6 col-xl-3">
            <div class="pedido-stat-card h-100">
                <div class="pedido-stat-label">Pedidos nesta página</div>
                <div class="pedido-stat-value">{{ $totalPagina }}</div>
                <div class="pedido-stat-helper">Resultados exibidos agora</div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="pedido-stat-card h-100">
                <div class="pedido-stat-label">Pagos</div>
                <div class="pedido-stat-value text-success">{{ $pagosPagina }}</div>
                <div class="pedido-stat-helper">Pedidos aprovados nesta listagem</div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="pedido-stat-card h-100">
                <div class="pedido-stat-label">Pendentes</div>
                <div class="pedido-stat-value text-warning">{{ $pendentesPagina }}</div>
                <div class="pedido-stat-helper">Aguardando pagamento</div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="pedido-stat-card h-100">
                <div class="pedido-stat-label">Valor líquido da página</div>
                <div class="pedido-stat-value text-primary">R$ {{ number_format($valorPagina, 2, ',', '.') }}</div>
                <div class="pedido-stat-helper">Soma dos pedidos exibidos</div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm pedidos-filter-card mb-4">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                <div>
                    <h2 class="h5 fw-bold mb-1">Filtros</h2>
                    <p class="text-muted mb-0">Refine a busca por status, e-mail e período.</p>
                </div>

                <a href="{{ route('admin.pedidos.index') }}" class="btn btn-light rounded-4 px-4 border">
                    Limpar filtros
                </a>
            </div>

            <form method="GET" action="{{ route('admin.pedidos.index') }}">
                <div class="row g-3">
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label fw-semibold">Status</label>
                        <select name="status" class="form-select rounded-4 pedidos-input">
                            <option value="">Todos</option>
                            <option value="aguardando_pagamento" {{ $statusAtual === 'aguardando_pagamento' ? 'selected' : '' }}>Aguardando pagamento</option>
                            <option value="pago" {{ $statusAtual === 'pago' ? 'selected' : '' }}>Pago</option>
                            <option value="cancelado" {{ $statusAtual === 'cancelado' ? 'selected' : '' }}>Cancelado</option>
                            <option value="expirado" {{ $statusAtual === 'expirado' ? 'selected' : '' }}>Expirado</option>
                        </select>
                    </div>

                    <div class="col-lg-4 col-md-6">
                        <label class="form-label fw-semibold">E-mail</label>
                        <input
                            type="text"
                            name="email"
                            class="form-control rounded-4 pedidos-input"
                            value="{{ $filtros['email'] ?? '' }}"
                            placeholder="Buscar por e-mail"
                        >
                    </div>

                    <div class="col-lg-2 col-md-6">
                        <label class="form-label fw-semibold">Data início</label>
                        <input
                            type="date"
                            name="data_inicio"
                            class="form-control rounded-4 pedidos-input"
                            value="{{ $filtros['data_inicio'] ?? '' }}"
                        >
                    </div>

                    <div class="col-lg-2 col-md-6">
                        <label class="form-label fw-semibold">Data fim</label>
                        <input
                            type="date"
                            name="data_fim"
                            class="form-control rounded-4 pedidos-input"
                            value="{{ $filtros['data_fim'] ?? '' }}"
                        >
                    </div>

                    <div class="col-lg-1 col-md-12 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary rounded-4 w-100 pedidos-filter-btn">
                            Filtrar
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm pedidos-table-card">
        <div class="card-body p-0">
            <div class="pedidos-table-header">
                <div>
                    <h2 class="h5 fw-bold mb-1">Listagem de pedidos</h2>
                    <p class="text-muted mb-0">
                        {{ $pedidos->total() }} {{ $pedidos->total() === 1 ? 'pedido encontrado' : 'pedidos encontrados' }}
                    </p>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table pedidos-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Pedido</th>
                            <th>Status</th>
                            <th>Cliente</th>
                            <th>Financeiro</th>
                            <th>Pagamento</th>
                            <th>Datas</th>
                            <th class="text-end">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($pedidos as $pedido)
                            @php
                                $valorBruto = (float) ($pedido->valor_original > 0 ? $pedido->valor_original : $pedido->subtotal);
                                $valorDesconto = (float) $pedido->desconto;
                                $valorLiquido = (float) $pedido->valor;
                            @endphp

                            <tr>
                                <td>
                                    <div class="pedido-main-cell">
                                        <div class="pedido-token">
                                            {{ \Illuminate\Support\Str::limit($pedido->token, 14, '...') }}
                                        </div>
                                        <div class="pedido-meta">
                                            {{ $pedido->quantidade }} {{ $pedido->quantidade === 1 ? 'jogo' : 'jogos' }}
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    @if($pedido->status === 'pago')
                                        <span class="pedido-status-badge pedido-status-success">Pago</span>
                                    @elseif($pedido->status === 'aguardando_pagamento')
                                        <span class="pedido-status-badge pedido-status-warning">Aguardando</span>
                                    @elseif($pedido->status === 'cancelado')
                                        <span class="pedido-status-badge pedido-status-danger">Cancelado</span>
                                    @elseif($pedido->status === 'expirado')
                                        <span class="pedido-status-badge pedido-status-secondary">Expirado</span>
                                    @else
                                        <span class="pedido-status-badge pedido-status-dark">{{ $pedido->status }}</span>
                                    @endif
                                </td>

                                <td>
                                    <div class="pedido-client-cell">
                                        <div class="pedido-email">{{ $pedido->email }}</div>
                                        <div class="pedido-submeta">
                                            Cupom:
                                            <strong>{{ $pedido->cupom_codigo ?: '—' }}</strong>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <div class="pedido-money-list">
                                        <div class="pedido-money-item">
                                            <span class="pedido-money-label">Bruto</span>
                                            <strong>R$ {{ number_format($valorBruto, 2, ',', '.') }}</strong>
                                        </div>
                                        <div class="pedido-money-item">
                                            <span class="pedido-money-label">Desconto</span>
                                            <strong class="text-success">R$ {{ number_format($valorDesconto, 2, ',', '.') }}</strong>
                                        </div>
                                        <div class="pedido-money-item">
                                            <span class="pedido-money-label">Líquido</span>
                                            <strong class="text-primary">R$ {{ number_format($valorLiquido, 2, ',', '.') }}</strong>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <div class="pedido-pay-cell">
                                        <div class="pedido-pay-gateway">{{ $pedido->gateway }}</div>
                                        <div class="pedido-submeta">
                                            Payment status:
                                            <strong>{{ $pedido->payment_status ?: '—' }}</strong>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <div class="pedido-date-cell">
                                        <div class="pedido-date-item">
                                            <span>Criado</span>
                                            <strong>{{ $pedido->created_at?->format('d/m/Y H:i') }}</strong>
                                        </div>

                                        <div class="pedido-date-item">
                                            <span>Pago</span>
                                            <strong>{{ $pedido->paid_at?->format('d/m/Y H:i') ?: '—' }}</strong>
                                        </div>
                                    </div>
                                </td>

                                <td class="text-end">
                                    <a href="{{ route('pedido.show', $pedido->token) }}" class="btn btn-outline-primary btn-sm rounded-4 px-3">
                                        Ver pedido
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <div class="pedidos-empty-state">
                                        <div class="pedidos-empty-icon">∅</div>
                                        <div class="pedidos-empty-title">Nenhum pedido encontrado</div>
                                        <div class="pedidos-empty-text">
                                            Tente ajustar os filtros para localizar os pedidos que você procura.
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($pedidos->hasPages())
                <div class="pedidos-pagination-wrap">
                    {{ $pedidos->links('pagination::bootstrap-5') }}
                </div>
            @endif
        </div>
    </div>
</div>

<style>
    .pedidos-page {
        color: #0f172a;
    }

    .pedidos-kicker {
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
        margin-bottom: 0.85rem;
    }

    .pedidos-title {
        font-size: 2rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        color: #0f172a;
    }

    .pedidos-subtitle {
        color: #64748b;
        max-width: 760px;
    }

    .pedidos-topbar {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
    }

    .pedido-stat-card {
        background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        border: 1px solid #e2e8f0;
        border-radius: 24px;
        padding: 1.35rem 1.4rem;
        box-shadow: 0 14px 35px rgba(15, 23, 42, 0.05);
    }

    .pedido-stat-label {
        font-size: 0.82rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #64748b;
        margin-bottom: 0.65rem;
    }

    .pedido-stat-value {
        font-size: 1.9rem;
        line-height: 1;
        font-weight: 800;
        color: #0f172a;
        margin-bottom: 0.45rem;
    }

    .pedido-stat-helper {
        color: #64748b;
        font-size: 0.92rem;
    }

    .pedidos-filter-card,
    .pedidos-table-card {
        border-radius: 28px;
        overflow: hidden;
    }

    .pedidos-input {
        min-height: 52px;
        border: 1px solid #dbe4f0;
        background: #fff;
    }

    .pedidos-input:focus {
        border-color: #93c5fd;
        box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.12);
    }

    .pedidos-filter-btn {
        min-height: 52px;
        font-weight: 700;
    }

    .pedidos-table-header {
        padding: 1.5rem 1.5rem 1rem 1.5rem;
        border-bottom: 1px solid #edf2f7;
        background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
    }

    .pedidos-table {
        --bs-table-bg: transparent;
        margin-bottom: 0;
    }

    .pedidos-table thead th {
        border-bottom: 1px solid #e9eef5;
        padding: 1rem 1.5rem;
        font-size: 0.78rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #64748b;
        background: #f8fafc;
        white-space: nowrap;
    }

    .pedidos-table tbody td {
        padding: 1.2rem 1.5rem;
        border-color: #eef2f7;
        vertical-align: middle;
    }

    .pedidos-table tbody tr {
        transition: background 0.18s ease;
    }

    .pedidos-table tbody tr:hover {
        background: #fbfdff;
    }

    .pedido-main-cell,
    .pedido-client-cell,
    .pedido-pay-cell,
    .pedido-date-cell {
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
    }

    .pedido-token {
        font-weight: 800;
        color: #0f172a;
        font-size: 1rem;
    }

    .pedido-meta,
    .pedido-submeta {
        color: #64748b;
        font-size: 0.9rem;
    }

    .pedido-email {
        font-weight: 700;
        color: #0f172a;
        word-break: break-word;
    }

    .pedido-pay-gateway {
        font-weight: 700;
        text-transform: lowercase;
        color: #0f172a;
    }

    .pedido-date-item {
        display: flex;
        flex-direction: column;
        gap: 0.15rem;
    }

    .pedido-date-item span {
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #94a3b8;
        font-weight: 700;
    }

    .pedido-date-item strong {
        color: #0f172a;
        font-size: 0.92rem;
    }

    .pedido-money-list {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        min-width: 160px;
    }

    .pedido-money-item {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        align-items: center;
    }

    .pedido-money-label {
        color: #64748b;
        font-size: 0.88rem;
    }

    .pedido-status-badge {
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

    .pedido-status-success {
        background: rgba(34, 197, 94, 0.14);
        color: #15803d;
    }

    .pedido-status-warning {
        background: rgba(245, 158, 11, 0.16);
        color: #a16207;
    }

    .pedido-status-danger {
        background: rgba(239, 68, 68, 0.14);
        color: #b91c1c;
    }

    .pedido-status-secondary {
        background: rgba(148, 163, 184, 0.18);
        color: #475569;
    }

    .pedido-status-dark {
        background: rgba(15, 23, 42, 0.10);
        color: #0f172a;
    }

    .pedidos-empty-state {
        text-align: center;
        padding: 3rem 1rem;
    }

    .pedidos-empty-icon {
        width: 70px;
        height: 70px;
        margin: 0 auto 1rem;
        border-radius: 50%;
        background: #eff6ff;
        color: #2563eb;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        font-weight: 800;
    }

    .pedidos-empty-title {
        font-size: 1.2rem;
        font-weight: 800;
        color: #0f172a;
        margin-bottom: 0.35rem;
    }

    .pedidos-empty-text {
        color: #64748b;
        max-width: 420px;
        margin: 0 auto;
    }

    .pedidos-pagination-wrap {
        padding: 1.25rem 1.5rem 1.5rem;
        border-top: 1px solid #eef2f7;
        background: #fff;
    }

    .pedidos-pagination-wrap .pagination {
        margin-bottom: 0;
    }

    .pedidos-pagination-wrap .page-link {
        border-radius: 14px !important;
        margin: 0 0.18rem;
        border: 1px solid #dbe4f0;
        color: #334155;
        padding: 0.65rem 0.95rem;
        box-shadow: none !important;
    }

    .pedidos-pagination-wrap .page-item.active .page-link {
        background: #2563eb;
        border-color: #2563eb;
        color: #fff;
    }

    .pedidos-pagination-wrap .page-item.disabled .page-link {
        background: #f8fafc;
        color: #94a3b8;
        border-color: #e2e8f0;
    }

    @media (max-width: 991px) {
        .pedidos-topbar {
            flex-direction: column;
        }

        .pedidos-table thead th,
        .pedidos-table tbody td {
            padding-left: 1rem;
            padding-right: 1rem;
        }
    }
</style>
@endsection