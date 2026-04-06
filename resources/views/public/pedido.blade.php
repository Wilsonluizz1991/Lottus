@extends('layouts.app')

@section('content')
<div class="container pt-2 pb-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4 p-md-5">
                    <h1 class="h2 fw-bold mb-4">Pedido do jogo</h1>

                    @if(session('success'))
                        <div class="alert alert-success">
                            {{ session('success') }}
                        </div>
                    @endif

                    @if(session('error'))
                        <div class="alert alert-danger">
                            {{ session('error') }}
                        </div>
                    @endif

                    @if(session('info'))
                        <div class="alert alert-info">
                            {{ session('info') }}
                        </div>
                    @endif

                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="border rounded-4 p-3 bg-light h-100">
                                <small class="text-muted d-block">Status</small>
                                <div id="pedido-status-bloco">
                                    @if($pedido->status === 'pago')
                                        <strong class="text-success">Pagamento aprovado</strong>
                                    @else
                                        <strong class="text-warning">Aguardando pagamento</strong>
                                    @endif
                                </div>
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
                                <small class="text-muted d-block">Valor final</small>
                                <strong>R$ {{ number_format((float) $pedido->valor, 2, ',', '.') }}</strong>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="border rounded-4 p-3 bg-light h-100">
                                <small class="text-muted d-block">Subtotal</small>
                                <strong>R$ {{ number_format((float) ($pedido->subtotal ?? $pedido->valor_original ?? $pedido->valor), 2, ',', '.') }}</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="border rounded-4 p-3 bg-light h-100">
                                <small class="text-muted d-block">Desconto</small>
                                <strong class="text-success">R$ {{ number_format((float) ($pedido->desconto ?? 0), 2, ',', '.') }}</strong>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="border rounded-4 p-3 bg-light h-100">
                                <small class="text-muted d-block">Cupom</small>
                                <strong>{{ $pedido->cupom_codigo ?: 'Não aplicado' }}</strong>
                            </div>
                        </div>
                    </div>

                    <div id="pedido-alerta-pagamento">
                        @if($pedido->isPaid() && $pedido->gateway === 'cupom')
                            <div class="alert alert-success border-0 shadow-sm">
                                <strong>Acesso liberado via cupom.</strong>
                                Seu pedido foi aprovado automaticamente e seus jogos já estão disponíveis.
                            </div>
                        @elseif($pedido->isPaid())
                            <div class="alert alert-success border-0 shadow-sm">
                                <strong>Pagamento confirmado.</strong>
                                Seus jogos foram liberados.
                            </div>
                        @else
                            <div class="alert alert-info border-0 shadow-sm">
                                Seus jogos estão reservados. Efetue o pagamento para liberar o conteúdo completo.
                            </div>
                        @endif
                    </div>

                    @unless($pedido->isPaid())
                        <div class="card border-0 shadow-sm mb-4 pagamento-instrucoes-card">
                            <div class="card-body p-4">
                                <h2 class="h4 fw-bold mb-3">Como funciona o pagamento</h2>

                                <div class="pagamento-passos">
                                    <div class="pagamento-passo">
                                        <div class="pagamento-passo-numero">1</div>
                                        <div class="pagamento-passo-texto">
                                            Clique em <strong>Pagar agora</strong> para abrir o ambiente seguro do Mercado Pago.
                                        </div>
                                    </div>

                                    <div class="pagamento-passo">
                                        <div class="pagamento-passo-numero">2</div>
                                        <div class="pagamento-passo-texto">
                                            Escolha a forma de pagamento desejada e conclua o processo.
                                        </div>
                                    </div>

                                    <div class="pagamento-passo">
                                        <div class="pagamento-passo-numero">3</div>
                                        <div class="pagamento-passo-texto">
                                            Assim que o pagamento for confirmado, esta página será atualizada automaticamente e seus jogos serão liberados.
                                        </div>
                                    </div>
                                </div>

                                <div class="alert alert-primary mt-4 mb-3">
                                    <strong>Pagamento com cartão:</strong> após a aprovação, o Mercado Pago normalmente redireciona você automaticamente de volta para esta página.
                                </div>

                                <div class="alert alert-warning mb-0">
                                    <strong>Pagamento via PIX:</strong> após concluir o pagamento, pode ser necessário clicar em <strong>“Voltar ao site”</strong> no Mercado Pago para retornar à página do pedido.
                                </div>
                            </div>
                        </div>
                    @endunless

                    <div class="row g-4 mb-4">
                        @foreach(($pedido->jogo ?? []) as $index => $jogo)
                            <div class="col-md-6">
                                <div class="card border-0 shadow-sm h-100">
                                    <div class="card-body p-4">
                                        <h2 class="h5 fw-bold mb-3">
                                            Jogo {{ $index + 1 }}
                                        </h2>

                                        <div class="{{ $pedido->isPaid() ? '' : 'jogo-bloqueado' }}" data-jogo-bloqueado>
                                            <div class="d-flex flex-wrap gap-2 mb-4">
                                                @foreach($jogo as $dezena)
                                                    <span class="badge rounded-pill text-bg-primary px-3 py-2 fs-6">
                                                        {{ str_pad($dezena, 2, '0', STR_PAD_LEFT) }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        </div>

                                        <div class="pedido-alerta-jogo">
                                            @unless($pedido->isPaid())
                                                <div class="alert alert-warning mt-4 mb-0">
                                                    Este jogo será exibido após a confirmação do pagamento.
                                                </div>
                                            @endunless
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @unless($pedido->isPaid())
                        @if(!empty($checkoutUrl) && (float) $pedido->valor > 0)
                            <a href="{{ $checkoutUrl }}" class="btn btn-primary btn-lg">
                                Pagar agora
                            </a>

                            <p class="small text-muted mt-3 mb-0">
                                Após a confirmação do pagamento, você poderá ser redirecionado automaticamente de volta para esta página.
                            </p>
                        @else
                            <div class="alert alert-warning mb-0">
                                Não foi possível gerar o link de pagamento neste momento. Atualize a página e tente novamente.
                            </div>
                        @endif
                    @else
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <span class="badge rounded-pill text-bg-success px-3 py-2">
                                {{ $pedido->gateway === 'cupom' ? 'Liberado via cupom' : 'Pagamento confirmado' }}
                            </span>

                            @if($pedido->cupom_codigo)
                                <span class="badge rounded-pill text-bg-light border text-dark px-3 py-2">
                                    Cupom: {{ $pedido->cupom_codigo }}
                                </span>
                            @endif
                        </div>
                    @endunless
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .jogo-bloqueado {
        filter: blur(8px);
        pointer-events: none;
        user-select: none;
    }

    .pagamento-instrucoes-card {
        border-radius: 20px;
        background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    }

    .pagamento-passos {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .pagamento-passo {
        display: flex;
        align-items: flex-start;
        gap: 1rem;
    }

    .pagamento-passo-numero {
        width: 36px;
        height: 36px;
        min-width: 36px;
        border-radius: 50%;
        background: #0d6efd;
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 1rem;
        box-shadow: 0 8px 18px rgba(13, 110, 253, 0.18);
    }

    .pagamento-passo-texto {
        font-size: 1rem;
        line-height: 1.6;
        color: #334155;
    }
</style>

@if(!$pedido->isPaid())
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const statusUrl = @json(route('pedido.status', $pedido->token));
        let pedidoJaFoiPago = false;

        async function verificarStatusPedido() {
            if (pedidoJaFoiPago) {
                return;
            }

            try {
                const response = await fetch(statusUrl, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    cache: 'no-store',
                });

                if (!response.ok) {
                    return;
                }

                const data = await response.json();

                if (data.is_paid) {
                    pedidoJaFoiPago = true;
                    window.location.reload();
                }
            } catch (error) {
                console.error('Erro ao verificar status do pedido:', error);
            }
        }

        setInterval(verificarStatusPedido, 5000);
    });
</script>
@endif
@endsection