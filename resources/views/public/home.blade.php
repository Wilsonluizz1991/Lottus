@extends('layouts.app')

@section('content')
<div class="container py-5">
    <div class="row justify-content-center g-4">
        <div class="col-xl-10">

            <div class="card border-0 shadow-lg overflow-hidden mb-4">
                <div class="card-body p-4 p-md-5">
                    <div class="row align-items-center g-4">
                        <div class="col-lg-7">
                            <span class="badge text-bg-primary px-3 py-2 mb-3">
                                Plataforma de jogos inteligentes para Lotofácil
                            </span>

                            <h1 class="display-5 fw-bold mb-3">Lottus</h1>

                            <p class="lead text-muted mb-4">
                                Gere um jogo da Lotofácil com base em estatística avançada, utilizando análise de até
                                <strong>500 concursos anteriores</strong>, filtros estruturais e critérios técnicos que
                                buscam entregar uma combinação mais consistente do que uma escolha feita no puro acaso.
                            </p>

                            <div class="d-flex flex-wrap gap-2 mb-4">
                                <span class="badge rounded-pill text-bg-light border text-dark px-3 py-2">
                                    Equilíbrio de pares e ímpares
                                </span>
                                <span class="badge rounded-pill text-bg-light border text-dark px-3 py-2">
                                    Números quentes e atrasados
                                </span>
                                <span class="badge rounded-pill text-bg-light border text-dark px-3 py-2">
                                    Moldura e centro
                                </span>
                                <span class="badge rounded-pill text-bg-light border text-dark px-3 py-2">
                                    Primos e distribuição
                                </span>
                            </div>

                            @if(session('error'))
                                <div class="alert alert-danger">
                                    {{ session('error') }}
                                </div>
                            @endif

                            @error('email')
                                <div class="alert alert-danger">
                                    {{ $message }}
                                </div>
                            @enderror

                            @if($ultimoConcurso)
                                <div class="alert alert-info border-0 shadow-sm">
                                    <div class="fw-semibold mb-1">Base de análise do Lottus</div>

                                    <div>
                                        O Lottus não se baseia apenas no último sorteio. A geração dos jogos considera
                                        o comportamento das dezenas em <strong>até 500 concursos anteriores</strong>,
                                        cruzando padrões de curto, médio e longo prazo para montar combinações
                                        mais consistentes.
                                    </div>

                                    <div class="mt-2 small">
                                        Último concurso disponível:
                                        <strong>{{ $ultimoConcurso->concurso }}</strong>
                                        ({{ optional($ultimoConcurso->data_sorteio)->format('d/m/Y') }})
                                    </div>
                                </div>
                            @endif
                        </div>

                        <div class="col-lg-5">
                            <div class="card border-0 shadow-sm bg-light">
                                <div class="card-body p-4">
                                    <h2 class="h4 fw-bold mb-3">Gerar jogo</h2>
                                    <p class="text-muted mb-3">
                                        Informe seu e-mail para gerar seu jogo e liberar após a confirmação do pagamento.
                                    </p>

                                    <form method="POST" action="{{ route('jogos.gerar') }}">
                                        @csrf

                                        <div class="mb-3">
                                            <label class="form-label fw-semibold">Seu e-mail</label>
                                            <input
                                                type="email"
                                                name="email"
                                                class="form-control form-control-lg"
                                                value="{{ old('email') }}"
                                                placeholder="voce@exemplo.com"
                                                required
                                            >
                                        </div>

                                        <div class="border rounded bg-white p-3 mb-3">
                                            <div class="small text-muted mb-1">Valor do jogo</div>
                                            <div class="fs-3 fw-bold text-primary">R$ {{ $preco }}</div>
                                        </div>

                                        <button class="btn btn-primary btn-lg w-100 py-3 fw-semibold shadow-sm" type="submit">
                                            Gerar jogo agora
                                        </button>
                                    </form>

                                    <p class="small text-muted mt-3 mb-0">
                                        O jogo é gerado primeiro e liberado somente após a confirmação do pagamento.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4 p-md-5">
                    <h2 class="h3 fw-bold mb-3">Como o Lottus valoriza o seu jogo</h2>
                    <p class="text-muted mb-4">
                        O Lottus não entrega uma combinação montada no chute. Antes de um jogo chegar até você,
                        ele passa por uma série de análises e validações para buscar uma estrutura mais forte,
                        mais equilibrada e tecnicamente mais consistente.
                    </p>

                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="border rounded-4 p-4 h-100 bg-light">
                                <h3 class="h5 fw-bold mb-2">1. Leitura do histórico dos concursos</h3>
                                <p class="text-muted mb-0">
                                    O sistema analisa o comportamento das dezenas em diferentes janelas do histórico
                                    para entender o que está mais forte no curto, médio e longo prazo.
                                </p>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="border rounded-4 p-4 h-100 bg-light">
                                <h3 class="h5 fw-bold mb-2">2. Avaliação de números quentes e atrasados</h3>
                                <p class="text-muted mb-0">
                                    O jogo considera dezenas que estão em evidência e também números com atraso
                                    estatisticamente interessante, buscando uma combinação mais inteligente.
                                </p>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="border rounded-4 p-4 h-100 bg-light">
                                <h3 class="h5 fw-bold mb-2">3. Equilíbrio de pares e ímpares</h3>
                                <p class="text-muted mb-0">
                                    O sistema evita jogos muito desbalanceados e prioriza combinações que respeitam
                                    padrões comuns de equilíbrio entre dezenas pares e ímpares.
                                </p>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="border rounded-4 p-4 h-100 bg-light">
                                <h3 class="h5 fw-bold mb-2">4. Distribuição no volante</h3>
                                <p class="text-muted mb-0">
                                    O jogo passa por filtros que analisam a distribuição dos números no volante,
                                    evitando concentrações exageradas e buscando uma ocupação mais consistente.
                                </p>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="border rounded-4 p-4 h-100 bg-light">
                                <h3 class="h5 fw-bold mb-2">5. Moldura, centro e estrutura geométrica</h3>
                                <p class="text-muted mb-0">
                                    O sistema valida a relação entre moldura e centro, além de outras estruturas do volante,
                                    descartando jogos que fogem demais dos padrões mais frequentes.
                                </p>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="border rounded-4 p-4 h-100 bg-light">
                                <h3 class="h5 fw-bold mb-2">6. Primos, sequências e repetições</h3>
                                <p class="text-muted mb-0">
                                    Também entram na análise critérios como presença de números primos,
                                    limite de sequências e repetição controlada em relação ao último concurso.
                                </p>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="border rounded-4 p-4 h-100 bg-light">
                                <h3 class="h5 fw-bold mb-2">7. Geração de milhares de combinações</h3>
                                <p class="text-muted mb-0">
                                    Em vez de entregar um jogo qualquer, o sistema gera milhares de combinações internas
                                    e elimina as mais fracas até encontrar as candidatas mais fortes.
                                </p>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="border rounded-4 p-4 h-100 bg-light">
                                <h3 class="h5 fw-bold mb-2">8. Seleção final do jogo</h3>
                                <p class="text-muted mb-0">
                                    Depois de passar por toda a filtragem, o jogo final é escolhido entre os melhores
                                    candidatos encontrados, entregando uma aposta tecnicamente melhor trabalhada.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


            <div class="card border-0 shadow-sm border-danger">
                <div class="card-body p-4 p-md-5">
                    <h2 class="h4 fw-bold text-danger mb-3">ATENÇÃO</h2>

                    <p class="mb-3">
                        O Lottus <strong>não vende promessa de prêmio</strong>, <strong>não garante acerto</strong>
                        e <strong>não oferece garantia de 15 pontos</strong>.
                    </p>

                    <p class="mb-0 text-muted">
                        O que a plataforma entrega é um jogo <strong>tecnicamente mais bem estruturado</strong>,
                        construído com base em histórico de concursos, estatística, equilíbrio e critérios de validação.
                        Em outras palavras: você não está comprando uma garantia de resultado, e sim uma combinação
                        trabalhada com muito mais critério do que uma aposta feita puramente no acaso.
                    </p>
                </div>
            </div>

        </div>
    </div>
</div>
@endsection 