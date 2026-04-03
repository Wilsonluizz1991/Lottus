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

                    @if($pedido->isPaid())
                        <div class="alert alert-success">
                            Pagamento confirmado. Seus jogos foram liberados.
                        </div>
                    @else
                        <div class="alert alert-info">
                            Seus jogos estão reservados. A liberação do conteúdo completo acontecerá quando o serviço de pagamento estiver disponível.
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
                                        </div>

                                        @unless($pedido->isPaid())
                                            <div class="alert alert-warning mt-4 mb-0">
                                                Este jogo será exibido após a liberação do serviço de pagamento.
                                            </div>
                                        @endunless
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @unless($pedido->isPaid())
                        <button type="button" class="btn btn-primary btn-lg" id="btn-pagar-agora">
                            Pagar agora
                        </button>

                        <p class="small text-muted mt-3 mb-0">
                            O serviço de pagamento estará disponível em breve.
                        </p>
                    @endunless
                </div>
            </div>
        </div>
    </div>
</div>

@unless($pedido->isPaid())
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const btnPagarAgora = document.getElementById('btn-pagar-agora');

        if (btnPagarAgora) {
            btnPagarAgora.addEventListener('click', function () {
                alert('Serviço ainda não disponível.');
            });
        }
    });
</script>
@endunless

<style>
    .jogo-bloqueado {
        filter: blur(8px);
        pointer-events: none;
        user-select: none;
    }
</style>
@endsection