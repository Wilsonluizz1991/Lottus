@extends('layouts.app')

@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4 p-md-5">
                    <h1 class="h3 mb-3">Pedido do jogo</h1>

                    <div class="mb-3">
                        <strong>Status:</strong>
                        @if($pedido->status === 'pago')
                            <span class="badge text-bg-success">Pago</span>
                        @elseif($pedido->status === 'aguardando_pagamento')
                            <span class="badge text-bg-warning">Aguardando pagamento</span>
                        @elseif($pedido->status === 'expirado')
                            <span class="badge text-bg-secondary">Expirado</span>
                        @else
                            <span class="badge text-bg-danger">{{ ucfirst($pedido->status) }}</span>
                        @endif
                    </div>

                    <p class="mb-1"><strong>E-mail:</strong> {{ $pedido->email }}</p>
                    <p class="mb-1"><strong>Valor:</strong> R$ {{ number_format($pedido->valor, 2, ',', '.') }}</p>
                    <p class="mb-4"><strong>Concurso base:</strong> {{ $pedido->concursoBase->concurso ?? '-' }}</p>
                    <div class="mb-4">
                        <p class="mb-1">
                            <strong>Base estatística utilizada:</strong>
                            este jogo foi construído a partir da análise de <strong>até 500 concursos anteriores</strong>,
                            combinando padrões de frequência, recência, equilíbrio e distribuição das dezenas.
                        </p>

                        <p class="mb-0 text-muted">
                            O concurso {{ $pedido->concursoBase->concurso ?? '-' }} é apenas a referência mais recente
                            dentro dessa base estatística.
                        </p>
                    </div>

                    @if($pedido->isPaid())
                        <div class="alert alert-success">
                            Pagamento confirmado. Seu jogo foi liberado.
                        </div>

                        <div class="d-flex flex-wrap gap-2 mb-4">
                            @foreach($pedido->jogo as $dezena)
                                <span class="badge rounded-pill text-bg-primary px-3 py-2 fs-6">
                                    {{ str_pad($dezena, 2, '0', STR_PAD_LEFT) }}
                                </span>
                            @endforeach
                        </div>

                        @if($pedido->analise)
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="border rounded p-3 bg-light">
                                        <small class="text-muted d-block">Pares / Ímpares</small>
                                        <strong>{{ $pedido->analise['pares'] ?? '-' }} / {{ $pedido->analise['impares'] ?? '-' }}</strong>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-3 bg-light">
                                        <small class="text-muted d-block">Soma</small>
                                        <strong>{{ $pedido->analise['soma'] ?? '-' }}</strong>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="border rounded p-3 bg-light">
                                        <small class="text-muted d-block">Quentes</small>
                                        <strong>{{ $pedido->analise['quentes'] ?? '-' }}</strong>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @else
                        <div class="alert alert-info">
                            Seu jogo foi gerado, mas está bloqueado até a confirmação do pagamento.
                        </div>

                        @if($pedido->payment_url)
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalPagamento">
                                Ver opções de pagamento
                            </button>
                        @endif

                        <p class="small text-muted mt-3 mb-0">
                            Esta página atualiza automaticamente.
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

@if(!$pedido->isPaid() && $pedido->payment_url)
<div class="modal fade" id="modalPagamento" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Finalizar pagamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">
                    Para receber o jogo gerado, finalize o pagamento de
                    <strong>R$ {{ number_format($pedido->valor, 2, ',', '.') }}</strong>.
                </p>

                <a href="{{ $pedido->payment_url }}" target="_blank" class="btn btn-success w-100">
                    Pagar com Pix ou cartão
                </a>
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
@endsection