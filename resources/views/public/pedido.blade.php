@extends('layouts.app')

@section('content')
<div class="container pt-2 pb-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4 p-md-5">
                    <h1 class="h2 fw-bold mb-4">Pedido do jogo</h1>

                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="border rounded-4 p-3 bg-light h-100">
                                <small class="text-muted d-block">Status</small>
                                @if($pedido->status === 'pago')
                                    <strong class="text-success">Pagamento aprovado</strong>
                                @else
                                    <strong class="text-warning">Aguardando pagamento</strong>
                                @endif
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="border rounded-4 p-3 bg-light h-100">
                                <small class="text-muted d-block">E-mail</small>
                                <strong>{{ $pedido->email }}</strong>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="border rounded-4 p-3 bg-light h-100">
                                <small class="text-muted d-block">Quantidade</small>
                                <strong>{{ $pedido->quantidade }} {{ $pedido->quantidade === 1 ? 'jogo' : 'jogos' }}</strong>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="border rounded-4 p-3 bg-light h-100">
                                <small class="text-muted d-block">Valor total</small>
                                <strong>R$ {{ number_format($pedido->valor, 2, ',', '.') }}</strong>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <p class="mb-1">
                            <strong>Base estatística utilizada:</strong>
                            este pedido foi construído a partir da análise de <strong>até 500 concursos anteriores</strong>,
                            combinando padrões de frequência, recência, equilíbrio e distribuição das dezenas.
                        </p>

                        <p class="mb-0 text-muted">
                            O concurso {{ $pedido->concursoBase->concurso ?? '-' }} é apenas a referência mais recente dentro dessa base estatística.
                        </p>
                    </div>

                    @if($pedido->isPaid())
                        <div class="alert alert-success">
                            Pagamento confirmado. Seus jogos foram liberados.
                        </div>
                    @else
                        <div class="alert alert-info">
                            Seus jogos já foram gerados e estão reservados. Eles serão liberados assim que o pagamento for confirmado.
                        </div>
                    @endif

                    <div class="row g-4 mb-4">
                        @foreach(($pedido->jogo ?? []) as $index => $jogo)
                            <div class="col-md-6">
                                <div class="card border-0 shadow-sm h-100">
                                    <div class="card-body p-4">
                                        <h2 class="h5 fw-bold mb-3">
                                            Jogo {{ $index + 1 }}
                                        </h2>

                                        <div class="{{ $pedido->isPaid() ? '' : 'jogo-bloqueado' }}">
                                            <div class="d-flex flex-wrap gap-2 mb-4">
                                                @foreach($jogo as $dezena)
                                                    <span class="badge rounded-pill text-bg-primary px-3 py-2 fs-6">
                                                        {{ str_pad($dezena, 2, '0', STR_PAD_LEFT) }}
                                                    </span>
                                                @endforeach
                                            </div>

                                            @php
                                                $analise = $pedido->analise[$index] ?? null;
                                            @endphp

                                            @if($analise)
                                                <div class="row g-3">
                                                    <div class="col-6">
                                                        <div class="border rounded p-3 bg-light h-100">
                                                            <small class="text-muted d-block">Pares / Ímpares</small>
                                                            <strong>{{ $analise['pares'] ?? '-' }} / {{ $analise['impares'] ?? '-' }}</strong>
                                                        </div>
                                                    </div>

                                                    <div class="col-6">
                                                        <div class="border rounded p-3 bg-light h-100">
                                                            <small class="text-muted d-block">Soma</small>
                                                            <strong>{{ $analise['soma'] ?? '-' }}</strong>
                                                        </div>
                                                    </div>

                                                    <div class="col-6">
                                                        <div class="border rounded p-3 bg-light h-100">
                                                            <small class="text-muted d-block">Quentes</small>
                                                            <strong>{{ $analise['quentes'] ?? '-' }}</strong>
                                                        </div>
                                                    </div>

                                                    <div class="col-6">
                                                        <div class="border rounded p-3 bg-light h-100">
                                                            <small class="text-muted d-block">Atrasadas</small>
                                                            <strong>{{ $analise['atrasadas'] ?? '-' }}</strong>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endif
                                        </div>

                                        @unless($pedido->isPaid())
                                            <div class="alert alert-warning mt-4 mb-0">
                                                Este jogo será exibido por completo após a confirmação do pagamento.
                                            </div>
                                        @endunless
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @unless($pedido->isPaid())
                        <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#modalPagamento">
                            Ver opções de pagamento
                        </button>

                        <p class="small text-muted mt-3 mb-0">
                            Esta página atualiza automaticamente.
                        </p>
                    @endunless
                </div>
            </div>
        </div>
    </div>
</div>

@if(!$pedido->isPaid())
<div class="modal fade" id="modalPagamento" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Pagamento em configuração</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">
                    Seus jogos já foram gerados e estão reservados.
                </p>

                <p class="mb-3">
                    Total do pedido: <strong>R$ {{ number_format($pedido->valor, 2, ',', '.') }}</strong>
                </p>

                <div class="alert alert-warning mb-0">
                    A integração com Pix e cartão será conectada na próxima etapa.
                </div>
            </div>
        </div>
    </div>
</div>
@endif

@if(session('abrir_modal_pagamento'))
<script>
    window.addEventListener('load', function () {
        const modalElement = document.getElementById('modalPagamento');

        if (modalElement && window.bootstrap) {
            const modal = new window.bootstrap.Modal(modalElement);
            modal.show();
        }
    });
</script>
@endif

@if(!$pedido->isPaid())
<script>
    setTimeout(function () {
        window.location.reload();
    }, 10000);
</script>
@endif

<style>
    .jogo-bloqueado {
        filter: blur(8px);
        pointer-events: none;
        user-select: none;
    }
</style>
@endsection