@extends('layouts.app')

@section('content')
    <button class="lottus-sidebar-mobile-toggle" id="lottus-sidebar-mobile-toggle" type="button" aria-label="Abrir navegação">
        <span></span>
        <span></span>
        <span></span>
    </button>

    <div class="lottus-sidebar-backdrop" id="lottus-sidebar-backdrop"></div>

    <aside class="lottus-sidebar" id="lottus-sidebar" aria-label="Navegação da página">
        <div class="lottus-sidebar-inner">
            <div class="lottus-sidebar-brand">
                <div class="lottus-sidebar-brand-mark">L</div>
                <div class="lottus-sidebar-brand-text">
                    <div class="lottus-sidebar-brand-title">Lottus</div>
                    <div class="lottus-sidebar-brand-subtitle">Navegação rápida</div>
                </div>
            </div>

            <nav class="lottus-sidebar-nav">
                <a href="#gerar-jogo" class="lottus-sidebar-link active">
                    <span class="lottus-sidebar-link-icon">01</span>
                    <span class="lottus-sidebar-link-text">Iniciar Seleção</span>
                </a>

                @if ($ultimoConcurso)
                    <a href="#ultimo-resultado" class="lottus-sidebar-link">
                        <span class="lottus-sidebar-link-icon">02</span>
                        <span class="lottus-sidebar-link-text">Último concurso</span>
                    </a>
                @endif

                <a href="#sobre-lottus" class="lottus-sidebar-link">
                    <span class="lottus-sidebar-link-icon">03</span>
                    <span class="lottus-sidebar-link-text">Sobre nós</span>
                </a>

                <a href="#metodologia" class="lottus-sidebar-link">
                    <span class="lottus-sidebar-link-icon">04</span>
                    <span class="lottus-sidebar-link-text">Metodologia</span>
                </a>

                <a href="#jogo-responsavel" class="lottus-sidebar-link">
                    <span class="lottus-sidebar-link-icon">05</span>
                    <span class="lottus-sidebar-link-text">Jogo responsável</span>
                </a>
            </nav>
        </div>
    </aside>

    <div class="container pt-2 pb-5 lottus-main-content">
        <div class="row justify-content-center g-4">
            <div class="col-xl-10">

                <section id="gerar-jogo" class="lottus-section-anchor">
                    <div class="card border-0 shadow-lg overflow-hidden mb-4 hero-card">
                        <div class="card-body p-4 p-md-4">
                            <div class="row align-items-start g-3">
                                <div class="col-lg-7">
                                    <div class="hero-branding mb-3">
                                        <div class="hero-kicker-wrap mb-3">
                                            <span class="hero-kicker">
                                                Loteria inteligente
                                            </span>
                                        </div>

                                        <h1 class="hero-title mb-2">Lottus</h1>

                                        <div class="hero-subtitle mb-3">
                                            Estratégia, dados e inteligência estatística para jogos da Lotofácil
                                        </div>

                                        <p class="hero-description mb-0">
                                            Crie seleções de jogos da Lotofácil com base em um motor estatístico avançado,
                                            que constrói
                                            milhares de combinações candidatas, avalia cada cenário por score matemático e
                                            seleciona
                                            jogos com maior equilíbrio estrutural, consistência histórica e diversidade
                                            técnica.
                                        </p>
                                    </div>

                                    <div class="d-flex flex-wrap gap-2 mb-3">
                                        <span class="badge rounded-pill text-bg-light border text-dark px-3 py-2">
                                            Janelas estatísticas adaptativas
                                        </span>
                                        <span class="badge rounded-pill text-bg-light border text-dark px-3 py-2">
                                            Score, recorrência e comportamento histórico
                                        </span>
                                        <span class="badge rounded-pill text-bg-light border text-dark px-3 py-2">
                                            Equilíbrio estrutural do volante
                                        </span>
                                        <span class="badge rounded-pill text-bg-light border text-dark px-3 py-2">
                                            Portfólio técnico e diversidade
                                        </span>
                                    </div>

                                    @if (session('error'))
                                        <div class="alert alert-danger border-0 shadow-sm rounded-4">
                                            {{ session('error') }}
                                        </div>
                                    @endif

                                    @error('email')
                                        <div class="alert alert-danger border-0 shadow-sm rounded-4">
                                            {{ $message }}
                                        </div>
                                    @enderror

                                    @error('quantidade')
                                        <div class="alert alert-danger border-0 shadow-sm rounded-4">
                                            {{ $message }}
                                        </div>
                                    @enderror

                                    @error('quantidade_dezenas')
                                        <div class="alert alert-danger border-0 shadow-sm rounded-4">
                                            {{ $message }}
                                        </div>
                                    @enderror

                                    @error('cupom')
                                        <div class="alert alert-danger border-0 shadow-sm rounded-4">
                                            {{ $message }}
                                        </div>
                                    @enderror

                                    @if ($ultimoConcurso)
                                        <div class="alert alert-info border-0 shadow-sm rounded-4 p-4 lottus-base-alert">
                                            <div class="fw-semibold mb-2 fs-5">
                                                Base de análise da Lottus
                                            </div>

                                            <div class="mb-2">
                                                A Lottus não seleciona jogos no aleatório. Cada combinação é construída a
                                                partir de
                                                uma leitura estatística profunda do histórico, usando janelas de curto,
                                                médio e
                                                longo prazo para medir força recente, estabilidade, recorrência e
                                                comportamento das dezenas.
                                            </div>

                                            <div class="small text-muted mb-2">
                                                Além da análise de frequência, o sistema avalia os cenários por score
                                                técnico,
                                                preserva combinações com potencial estatístico e seleciona jogos com maior
                                                equilíbrio
                                                estrutural e diversidade matemática.
                                            </div>

                                            <div class="small">
                                                Último concurso disponível:
                                                <strong>{{ $ultimoConcurso->concurso }}</strong>
                                                ({{ optional($ultimoConcurso->data_sorteio)->format('d/m/Y') }})
                                            </div>
                                        </div>
                                    @endif
                                </div>

                                <div class="col-lg-5">
                                    <div class="card border-0 shadow-lg form-card lottus-generator-card">
                                        <div class="card-body p-4">
                                            <div class="lottus-product-tabs">
                                                <button type="button" class="lottus-tab-btn active"
                                                    data-target="simple-game-panel">
                                                    Seleção Inteligente
                                                </button>

                                                <button type="button" class="lottus-tab-btn"
                                                    data-target="fechamento-panel">
                                                    Fechamento Inteligente
                                                </button>
                                            </div>

                                            <div id="simple-game-panel" class="lottus-tab-panel active">
                                                <h2 class="h4 fw-bold mb-2">Seleção Inteligente Lottus</h2>
                                                <p class="text-muted mb-3">
                                                    Informe seu e-mail, escolha a quantidade de jogos e selecione
                                                    combinações para liberação após a confirmação do pagamento.
                                                </p>

                                                <form method="POST" action="{{ route('jogos.gerar') }}"
                                                    id="form-gerar-jogo">
                                                    @csrf

                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Seu e-mail</label>
                                                        <input type="email" name="email" id="email"
                                                            class="form-control form-control-lg rounded-4"
                                                            value="{{ old('email') }}" placeholder="voce@exemplo.com"
                                                            required>
                                                    </div>

                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Quantidade de jogos</label>
                                                        <select name="quantidade" id="quantidade"
                                                            class="form-select form-select-lg rounded-4" required>
                                                            @for ($i = 1; $i <= 10; $i++)
                                                                <option value="{{ $i }}"
                                                                    {{ old('quantidade', 1) == $i ? 'selected' : '' }}>
                                                                    {{ $i }} {{ $i === 1 ? 'jogo' : 'jogos' }}
                                                                </option>
                                                            @endfor
                                                        </select>
                                                    </div>

                                                    <div class="mb-3">
                                                        <label for="cupom" class="form-label fw-semibold">Cupom de
                                                            desconto</label>

                                                        <div class="cupom-layout">
                                                            <div class="cupom-input-wrap">
                                                                <input type="text"
                                                                    class="form-control form-control-lg rounded-4 cupom-input"
                                                                    id="cupom" placeholder="Digite seu cupom"
                                                                    value="{{ old('cupom') }}" autocomplete="off">
                                                            </div>

                                                            <div class="cupom-button-wrap">
                                                                <button type="button"
                                                                    class="btn btn-outline-primary rounded-4 w-100 btn-cupom-aplicar"
                                                                    id="btn-validar-cupom">
                                                                    Aplicar
                                                                </button>
                                                            </div>
                                                        </div>

                                                        <input type="hidden" id="cupom-aplicado" name="cupom"
                                                            value="{{ old('cupom') }}">

                                                        <div id="cupom-feedback" class="mt-2"></div>
                                                    </div>

                                                    <div class="border rounded-4 bg-white p-3 mb-3 resumo-preco">
                                                        <div
                                                            class="d-flex justify-content-between align-items-center mb-2">
                                                            <span class="small text-muted">Valor por jogo</span>
                                                            <div class="fw-semibold">R$ {{ $preco }}</div>
                                                        </div>

                                                        <div
                                                            class="d-flex justify-content-between align-items-center mb-2">
                                                            <span class="small text-muted">Subtotal</span>
                                                            <div class="fw-semibold" id="subtotal-preview">
                                                                R$ {{ number_format($valorUnitario, 2, ',', '.') }}
                                                            </div>
                                                        </div>

                                                        <div
                                                            class="d-flex justify-content-between align-items-center mb-2">
                                                            <span class="small text-muted">Desconto</span>
                                                            <div class="fw-semibold text-success" id="desconto-preview">
                                                                R$ 0,00
                                                            </div>
                                                        </div>

                                                        <hr class="my-3">

                                                        <div class="small text-muted mb-1">Valor total</div>
                                                        <div class="fs-3 fw-bold text-primary" id="valor-total">
                                                            R$ {{ number_format($valorUnitario, 2, ',', '.') }}
                                                        </div>
                                                    </div>

                                                    <button
                                                        class="btn btn-primary btn-lg w-100 py-3 fw-semibold shadow-sm rounded-4"
                                                        type="submit" id="btn-gerar-jogo">
                                                        Selecionar jogo agora
                                                    </button>
                                                </form>

                                                <p class="small text-muted mt-3 mb-0">
                                                    A Lottus seleciona jogos por análise estatística. Após receber suas
                                                    combinações, registre sua aposta no site oficial da Caixa ou no app
                                                    oficial.
                                                </p>
                                            </div>

                                            <div id="fechamento-panel" class="lottus-tab-panel">
                                                <h2 class="h4 fw-bold mb-2">Fechamento Inteligente Lottus</h2>
                                                <p class="text-muted mb-3">
                                                    Escolha o tamanho do fechamento. A Lottus analisa padrões estatísticos e
                                                    seleciona automaticamente as dezenas com maior potencial, criando jogos
                                                    otimizados com inteligência estratégica.
                                                </p>

                                                <form method="POST" action="{{ route('fechamento.gerar') }}"
                                                    id="form-gerar-fechamento">
                                                    @csrf

                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Seu e-mail</label>
                                                        <input type="email" name="email" id="email-fechamento"
                                                            class="form-control form-control-lg rounded-4"
                                                            value="{{ old('email') }}" placeholder="voce@exemplo.com"
                                                            required>
                                                    </div>

                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold">Tamanho do fechamento</label>

                                                        <select name="quantidade_dezenas"
                                                            id="quantidade-dezenas-fechamento"
                                                            class="form-select form-select-lg rounded-4 fechamento-select"
                                                            required>
                                                            <option value="16" data-price="7.90" data-games="16">16
                                                                dezenas — R$ 7,90</option>
                                                            <option value="17" data-price="11.90" data-games="24">17
                                                                dezenas — R$ 11,90</option>
                                                            <option value="18" data-price="17.90" data-games="36">18
                                                                dezenas — R$ 17,90</option>
                                                            <option value="19" data-price="27.90" data-games="60">19
                                                                dezenas — R$ 27,90</option>
                                                            <option value="20" data-price="39.90" data-games="90">20
                                                                dezenas — R$ 39,90</option>
                                                        </select>
                                                    </div>


                                                    <div class="mb-3">
                                                        <label for="cupom-fechamento" class="form-label fw-semibold">Cupom
                                                            de desconto</label>

                                                        <div class="cupom-layout">
                                                            <div class="cupom-input-wrap">
                                                                <input type="text"
                                                                    class="form-control form-control-lg rounded-4 cupom-input"
                                                                    id="cupom-fechamento" placeholder="Digite seu cupom"
                                                                    value="{{ old('cupom') }}" autocomplete="off">
                                                            </div>

                                                            <div class="cupom-button-wrap">
                                                                <button type="button"
                                                                    class="btn btn-outline-primary rounded-4 w-100 btn-cupom-aplicar"
                                                                    id="btn-validar-cupom-fechamento">
                                                                    Aplicar
                                                                </button>
                                                            </div>
                                                        </div>

                                                        <input type="hidden" id="cupom-aplicado-fechamento"
                                                            name="cupom" value="{{ old('cupom') }}">

                                                        <div id="cupom-feedback-fechamento" class="mt-2"></div>
                                                    </div>

                                                    <div
                                                        class="border rounded-4 bg-white p-3 mb-3 resumo-preco fechamento-resumo">
                                                        <div class="fechamento-resumo-row fechamento-produto-row">
                                                            <span
                                                                class="small text-muted fechamento-resumo-label fechamento-produto-label">Produto</span>
                                                            <div class="fw-semibold fechamento-resumo-value fechamento-produto-title">
                                                                Fechamento Inteligente
                                                            </div>
                                                        </div>

                                                        <div class="fechamento-resumo-row">
                                                            <span class="small text-muted fechamento-resumo-label">Dezenas analisadas</span>
                                                            <div class="fw-semibold fechamento-resumo-value" id="fechamento-dezenas-preview">
                                                                16 dezenas
                                                            </div>
                                                        </div>

                                                        <div class="fechamento-resumo-row">
                                                            <span class="small text-muted fechamento-resumo-label">Jogos entregues</span>
                                                            <div class="fw-semibold fechamento-resumo-value" id="fechamento-jogos-preview">
                                                                16 jogos
                                                            </div>
                                                        </div>

                                                        <div class="fechamento-resumo-row">
                                                            <span class="small text-muted fechamento-resumo-label">Subtotal</span>
                                                            <div class="fw-semibold fechamento-resumo-value" id="fechamento-subtotal-preview">
                                                                R$ 7,90
                                                            </div>
                                                        </div>

                                                        <div class="fechamento-resumo-row">
                                                            <span class="small text-muted fechamento-resumo-label">Desconto</span>
                                                            <div class="fw-semibold text-success fechamento-resumo-value"
                                                                id="fechamento-desconto-preview">
                                                                R$ 0,00
                                                            </div>
                                                        </div>

                                                        <hr class="my-3">

                                                        <div class="small text-muted mb-1">Valor total</div>
                                                        <div class="fs-3 fw-bold text-primary"
                                                            id="fechamento-valor-total">
                                                            R$ 7,90
                                                        </div>
                                                    </div>

                                                    <button
                                                        class="btn btn-primary btn-lg w-100 py-3 fw-semibold shadow-sm rounded-4 fechamento-submit-btn"
                                                        type="submit" id="btn-gerar-fechamento">
                                                        <span class="fechamento-submit-text-desktop">Selecionar fechamento inteligente</span>
                                                        <span class="fechamento-submit-text-mobile">Selecionar fechamento</span>
                                                    </button>
                                                </form>

                                                <p class="small text-muted mt-3 mb-0">
                                                    O Fechamento Inteligente Lottus é um produto premium. A Lottus seleciona
                                                    as dezenas e monta jogos reduzidos com base em análise estatística e
                                                    cobertura matemática.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                @if ($ultimoConcurso)
                    @php
                        $dezenas = $ultimoConcurso->dezenas ?? [];

                        $pares = collect($dezenas)->filter(fn($n) => $n % 2 === 0)->count();
                        $impares = collect($dezenas)->filter(fn($n) => $n % 2 !== 0)->count();
                        $somaDezenas = collect($dezenas)->sum();

                        $premiacoes = [
                            [
                                'faixa' => '15 acertos',
                                'ganhadores' => $ultimoConcurso->ganhadores_15_acertos,
                                'rateio' => $ultimoConcurso->rateio_15_acertos,
                                'destaque' => true,
                            ],
                            [
                                'faixa' => '14 acertos',
                                'ganhadores' => $ultimoConcurso->ganhadores_14_acertos,
                                'rateio' => $ultimoConcurso->rateio_14_acertos,
                                'destaque' => false,
                            ],
                            [
                                'faixa' => '13 acertos',
                                'ganhadores' => $ultimoConcurso->ganhadores_13_acertos,
                                'rateio' => $ultimoConcurso->rateio_13_acertos,
                                'destaque' => false,
                            ],
                            [
                                'faixa' => '12 acertos',
                                'ganhadores' => $ultimoConcurso->ganhadores_12_acertos,
                                'rateio' => $ultimoConcurso->rateio_12_acertos,
                                'destaque' => false,
                            ],
                            [
                                'faixa' => '11 acertos',
                                'ganhadores' => $ultimoConcurso->ganhadores_11_acertos,
                                'rateio' => $ultimoConcurso->rateio_11_acertos,
                                'destaque' => false,
                            ],
                        ];
                    @endphp

                    <section id="ultimo-resultado" class="lottus-section-anchor">
                        <div class="card border-0 shadow-lg overflow-hidden mb-4 resultado-card">
                            <div class="resultado-topo text-white p-4 p-md-5">
                                <div class="row align-items-center g-4">
                                    <div class="col-lg-8">
                                        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                                            <span class="badge rounded-pill bg-white text-primary px-3 py-2 fw-semibold">
                                                Último resultado oficial
                                            </span>

                                            <span class="badge rounded-pill resultado-topo-badge px-3 py-2">
                                                Concurso {{ $ultimoConcurso->concurso }}
                                            </span>
                                        </div>

                                        <h2 class="display-6 fw-bold mb-2">
                                            Resultado da Lotofácil
                                        </h2>

                                        <p class="mb-0 resultado-topo-texto fs-5">
                                            Sorteio realizado em
                                            <strong class="text-white">
                                                {{ optional($ultimoConcurso->data_sorteio)->format('d/m/Y') }}
                                            </strong>
                                            @if (!empty($ultimoConcurso->cidade_uf))
                                                • {{ $ultimoConcurso->cidade_uf }}
                                            @endif
                                        </p>
                                    </div>

                                    <div class="col-lg-4">
                                        <div class="resultado-highlight-card">
                                            <div class="small text-uppercase resultado-topo-texto mb-1">Estimativa próximo
                                                concurso</div>
                                            <div class="fs-2 fw-bold text-white">
                                                R$
                                                {{ number_format((float) $ultimoConcurso->estimativa_premio, 2, ',', '.') }}
                                            </div>
                                            <div class="small resultado-topo-texto mt-2">
                                                Base atual do sistema da Lottus
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card-body p-4 p-md-5">
                                <div class="row g-4">
                                    <div class="col-lg-8">
                                        <div class="resultado-box h-100">
                                            <div
                                                class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                                <div>
                                                    <h3 class="h4 fw-bold mb-1">Dezenas sorteadas</h3>
                                                    <p class="text-muted mb-0">
                                                        Resultado mais recente disponível na base oficial da Lottus.
                                                    </p>
                                                </div>

                                                <div class="text-md-end">
                                                    <div class="small text-muted">Origem dos dados</div>
                                                    <div class="fw-semibold">
                                                        {{ $ultimoConcurso->informado_manualmente ? 'Cadastro manual' : 'API oficial' }}
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="dezenas-grid mb-4">
                                                @foreach ($dezenas as $dezena)
                                                    <div class="dezena-bola">
                                                        {{ str_pad($dezena, 2, '0', STR_PAD_LEFT) }}
                                                    </div>
                                                @endforeach
                                            </div>

                                            <div class="row g-3">
                                                <div class="col-sm-4">
                                                    <div class="mini-stat">
                                                        <div class="mini-stat-label">Pares</div>
                                                        <div class="mini-stat-value">{{ $pares }}</div>
                                                    </div>
                                                </div>

                                                <div class="col-sm-4">
                                                    <div class="mini-stat">
                                                        <div class="mini-stat-label">Ímpares</div>
                                                        <div class="mini-stat-value">{{ $impares }}</div>
                                                    </div>
                                                </div>

                                                <div class="col-sm-4">
                                                    <div class="mini-stat">
                                                        <div class="mini-stat-label">Soma das dezenas</div>
                                                        <div class="mini-stat-value">{{ $somaDezenas }}</div>
                                                    </div>
                                                </div>
                                            </div>

                                            @if (!empty($ultimoConcurso->observacao))
                                                <div class="alert alert-light border rounded-4 mt-4 mb-0">
                                                    <div class="fw-semibold mb-1">Observação do concurso</div>
                                                    <div class="text-muted mb-0">
                                                        {{ $ultimoConcurso->observacao }}
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="col-lg-4">
                                        <div class="resultado-box h-100">
                                            <h3 class="h5 fw-bold mb-3">Resumo do concurso</h3>

                                            <div class="info-lista">
                                                <div class="info-item">
                                                    <span class="info-label">Concurso</span>
                                                    <span class="info-value">{{ $ultimoConcurso->concurso }}</span>
                                                </div>

                                                <div class="info-item">
                                                    <span class="info-label">Data</span>
                                                    <span
                                                        class="info-value">{{ optional($ultimoConcurso->data_sorteio)->format('d/m/Y') }}</span>
                                                </div>

                                                <div class="info-item">
                                                    <span class="info-label">Cidade / UF</span>
                                                    <span
                                                        class="info-value">{{ $ultimoConcurso->cidade_uf ?: 'Não informado' }}</span>
                                                </div>

                                                <div class="info-item">
                                                    <span class="info-label">Arrecadação</span>
                                                    <span class="info-value">
                                                        R$
                                                        {{ number_format((float) $ultimoConcurso->arrecadacao_total, 2, ',', '.') }}
                                                    </span>
                                                </div>

                                                <div class="info-item">
                                                    <span class="info-label">Estimativa próximo</span>
                                                    <span class="info-value">
                                                        R$
                                                        {{ number_format((float) $ultimoConcurso->estimativa_premio, 2, ',', '.') }}
                                                    </span>
                                                </div>

                                                <div class="info-item">
                                                    <span class="info-label">Acumulado 15 acertos</span>
                                                    <span class="info-value">
                                                        R$
                                                        {{ number_format((float) $ultimoConcurso->acumulado_15_acertos, 2, ',', '.') }}
                                                    </span>
                                                </div>

                                                <div class="info-item">
                                                    <span class="info-label">Especial Independência</span>
                                                    <span class="info-value">
                                                        R$
                                                        {{ number_format((float) $ultimoConcurso->acumulado_sorteio_especial_lotofacil_independencia, 2, ',', '.') }}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="resultado-box mt-4">
                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                        <div>
                                            <h3 class="h4 fw-bold mb-1">Premiação por faixa</h3>
                                            <p class="text-muted mb-0">
                                                Distribuição oficial de ganhadores e valores por faixa de acerto.
                                            </p>
                                        </div>
                                    </div>

                                    <div class="row g-3">
                                        @foreach ($premiacoes as $premio)
                                            <div class="col-md-6 col-xl">
                                                <div
                                                    class="premiacao-card {{ $premio['destaque'] ? 'premiacao-destaque' : '' }}">
                                                    <div class="small text-uppercase text-muted fw-semibold mb-2">
                                                        {{ $premio['faixa'] }}
                                                    </div>

                                                    <div class="fw-bold fs-4 mb-2">
                                                        R$ {{ number_format((float) $premio['rateio'], 2, ',', '.') }}
                                                    </div>

                                                    <div class="text-muted small">
                                                        {{ (int) $premio['ganhadores'] }}
                                                        {{ (int) $premio['ganhadores'] === 1 ? 'ganhador' : 'ganhadores' }}
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                @endif

                <section id="sobre-lottus" class="lottus-section-anchor">
                    <div class="card border-0 shadow-lg mb-4 sobre-card">
                        <div class="card-body p-4 p-md-5">
                            <div class="row align-items-center g-4">
                                <div class="col-lg-7">
                                    <div class="sobre-kicker mb-3">Sobre nós</div>

                                    <h2 class="h1 fw-bold mb-3 sobre-titulo">
                                        A Lottus nasceu para elevar o nível da seleção de jogos.
                                    </h2>

                                    <p class="sobre-descricao mb-3">
                                        Nós não tratamos a Lotofácil como uma simples escolha de números. A Lottus foi
                                        criada para transformar histórico em critério, estatística em leitura e combinação
                                        em estrutura.
                                    </p>

                                    <p class="sobre-descricao mb-0">
                                        Em vez de entregar jogos montados no puro acaso, nossa plataforma lê o histórico em
                                        múltiplas camadas, avalia milhares de combinações candidatas, calcula score
                                        estatístico e seleciona jogos com mais consistência, equilíbrio e diversidade
                                        técnica.
                                    </p>
                                </div>

                                <div class="col-lg-5">
                                    <div class="sobre-highlight">
                                        <div class="sobre-highlight-label">Posicionamento do produto</div>
                                        <div class="sobre-highlight-title">Mais que um gerador</div>
                                        <p class="sobre-highlight-text mb-0">
                                            A Lottus atua como um motor de análise e seleção técnica de jogos, desenvolvido
                                            para entregar combinações mais equilibradas, mais bem ranqueadas e muito mais
                                            trabalhadas do que escolhas aleatórias.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-4 mt-1">
                                <div class="col-md-6 col-xl-3">
                                    <div class="sobre-mini-card h-100">
                                        <div class="sobre-mini-numero">01</div>
                                        <h3 class="h5 fw-bold mb-2">Base histórica real</h3>
                                        <p class="text-muted mb-0">
                                            O sistema analisa concursos anteriores em múltiplas janelas para medir
                                            frequência, estabilidade, recorrência e comportamento estatístico das dezenas.
                                        </p>
                                    </div>
                                </div>

                                <div class="col-md-6 col-xl-3">
                                    <div class="sobre-mini-card h-100">
                                        <div class="sobre-mini-numero">02</div>
                                        <h3 class="h5 fw-bold mb-2">Critério acima do acaso</h3>
                                        <p class="text-muted mb-0">
                                            Cada combinação é avaliada por score estatístico, equilíbrio estrutural e
                                            consistência histórica antes de compor a seleção final.
                                        </p>
                                    </div>
                                </div>

                                <div class="col-md-6 col-xl-3">
                                    <div class="sobre-mini-card h-100">
                                        <div class="sobre-mini-numero">03</div>
                                        <h3 class="h5 fw-bold mb-2">Milhares de cenários</h3>
                                        <p class="text-muted mb-0">
                                            A Lottus gera internamente milhares de possibilidades, remove duplicidades e
                                            seleciona apenas candidatas com maior força técnica.
                                        </p>
                                    </div>
                                </div>

                                <div class="col-md-6 col-xl-3">
                                    <div class="sobre-mini-card h-100">
                                        <div class="sobre-mini-numero">04</div>
                                        <h3 class="h5 fw-bold mb-2">Jogo mais refinado</h3>
                                        <p class="text-muted mb-0">
                                            Você não compra promessa de prêmio. Você acessa jogos selecionados com muito
                                            mais leitura, técnica, diversidade e inteligência.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="sobre-faixa mt-4">
                                <div class="row g-3 align-items-center">
                                    <div class="col-lg-8">
                                        <h3 class="h4 fw-bold mb-2">Nossa proposta é simples</h3>
                                        <p class="mb-0 text-muted">
                                            Se a loteria é um jogo de probabilidade, então a escolha das combinações também
                                            deve ser tratada com método. A Lottus existe para substituir o chute por um
                                            processo muito mais técnico, estratégico e criterioso.
                                        </p>
                                    </div>

                                    <div class="col-lg-4">
                                        <div class="sobre-frase-impacto">
                                            Dados, score e inteligência aplicados à seleção dos seus jogos.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section id="metodologia" class="lottus-section-anchor">
                    <div class="card border-0 shadow-lg mb-4 info-card">
                        <div class="card-body p-4 p-md-5">
                            <h2 class="h3 fw-bold mb-3">Como a Lottus valoriza o seu jogo</h2>
                            <p class="text-muted mb-4">
                                A Lottus não entrega uma combinação montada no chute. Antes de um jogo chegar até você,
                                ele passa por uma sequência de análises estatísticas, ranqueamento por score e validações
                                estruturais
                                para buscar uma composição mais forte, mais equilibrada e tecnicamente muito mais
                                consistente.
                            </p>

                            <div class="row g-4">
                                <div class="col-md-6">
                                    <div class="feature-card h-100">
                                        <h3 class="h5 fw-bold mb-2">1. Leitura multicamadas do histórico</h3>
                                        <p class="text-muted mb-0">
                                            O sistema analisa o histórico em diferentes janelas estatísticas para entender
                                            força recente, estabilidade de longo prazo e o comportamento real das dezenas ao
                                            longo do tempo.
                                        </p>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="feature-card h-100">
                                        <h3 class="h5 fw-bold mb-2">2. Score avançado das dezenas</h3>
                                        <p class="text-muted mb-0">
                                            Cada número recebe um peso calculado por frequência, recorrência e comportamento
                                            histórico,
                                            formando uma base estatística muito mais refinada para a seleção.
                                        </p>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="feature-card h-100">
                                        <h3 class="h5 fw-bold mb-2">3. Equilíbrio estrutural</h3>
                                        <p class="text-muted mb-0">
                                            O sistema avalia soma, pares, repetições e distribuição das dezenas para
                                            priorizar
                                            combinações mais consistentes dentro do comportamento histórico da Lotofácil.
                                        </p>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="feature-card h-100">
                                        <h3 class="h5 fw-bold mb-2">4. Distribuição no volante</h3>
                                        <p class="text-muted mb-0">
                                            O jogo passa por validações de faixas, quadrantes, linhas e colunas para evitar
                                            concentrações exageradas e buscar uma ocupação muito mais consistente do
                                            volante.
                                        </p>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="feature-card h-100">
                                        <h3 class="h5 fw-bold mb-2">5. Estrutura e padrões do jogo</h3>
                                        <p class="text-muted mb-0">
                                            O sistema observa relações estruturais entre dezenas para reduzir ruído e
                                            priorizar
                                            combinações com melhor organização matemática.
                                        </p>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="feature-card h-100">
                                        <h3 class="h5 fw-bold mb-2">6. Recorrência e composição numérica</h3>
                                        <p class="text-muted mb-0">
                                            Também entram na análise repetições com concursos recentes, frequência
                                            histórica,
                                            baixa recorrência e composição numérica, formando uma leitura mais profunda do
                                            volante.
                                        </p>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="feature-card h-100">
                                        <h3 class="h5 fw-bold mb-2">7. Geração massiva com seleção técnica</h3>
                                        <p class="text-muted mb-0">
                                            Em vez de entregar um jogo qualquer, o sistema gera milhares de combinações
                                            internas,
                                            pontua os cenários candidatos e preserva jogos com maior potencial estatístico.
                                        </p>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="feature-card h-100">
                                        <h3 class="h5 fw-bold mb-2">8. Seleção final mais refinada</h3>
                                        <p class="text-muted mb-0">
                                            Depois de pontuar os melhores cenários, a Lottus escolhe os jogos finais dentro
                                            de uma
                                            elite diversificada de candidatas, entregando combinações muito melhor
                                            trabalhadas.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section id="jogo-responsavel" class="lottus-section-anchor">
                    <div class="card border-0 shadow-lg mb-4 transparencia-card">
                        <div class="card-body p-4 p-md-5">
                            <div class="row g-4 align-items-center">
                                <div class="col-lg-7">
                                    <div class="transparencia-kicker mb-3">Jogo responsável</div>

                                    <h2 class="transparencia-title mb-3">
                                        Inteligência na escolha, seriedade na comunicação.
                                    </h2>

                                    <p class="transparencia-description mb-3">
                                        A Lottus não vende ilusão, não promete prêmio e não oferece garantias irreais de
                                        acerto. Nossa proposta é outra: entregar jogos tecnicamente mais bem construídos,
                                        com muito mais critério do que uma escolha feita no puro acaso.
                                    </p>

                                    <p class="transparencia-description mb-0">
                                        A loteria continua sendo um jogo de probabilidade. O que a Lottus faz é elevar a
                                        qualidade da seleção, aplicando leitura histórica, score estatístico, balanceamento
                                        estrutural e inteligência matemática para chegar a combinações mais consistentes.
                                    </p>
                                </div>

                                <div class="col-lg-5">
                                    <div class="transparencia-highlight">
                                        <div class="transparencia-highlight-label">Compromisso da marca</div>
                                        <div class="transparencia-highlight-title">Sem promessa fácil</div>
                                        <p class="transparencia-highlight-text mb-0">
                                            Você não está comprando uma garantia de resultado. Está acessando um processo
                                            mais técnico, mais criterioso e muito mais sofisticado de seleção de jogos.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mt-1">
                                <div class="col-md-4">
                                    <div class="transparencia-item h-100">
                                        <div class="transparencia-item-title">O que a Lottus não faz</div>
                                        <p class="mb-0 text-muted">
                                            Não promete 15 pontos, não vende certeza de prêmio e não usa discurso enganoso
                                            para induzir compra.
                                        </p>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="transparencia-item h-100">
                                        <div class="transparencia-item-title">O que a Lottus entrega</div>
                                        <p class="mb-0 text-muted">
                                            Jogos analisados com base em histórico, score estatístico, equilíbrio estrutural
                                            e critérios técnicos muito mais avançados.
                                        </p>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="transparencia-item h-100">
                                        <div class="transparencia-item-title">Por que isso importa</div>
                                        <p class="mb-0 text-muted">
                                            Porque selecionar jogos com método, leitura e estrutura é diferente de escolher
                                            no chute. E essa diferença tem valor.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="transparencia-footer mt-4">
                                <strong>Lottus:</strong> mais responsabilidade no discurso, mais inteligência na seleção,
                                mais qualidade nos seus jogos. Jogo proibido para menores de 18 anos.
                            </div>
                        </div>
                    </div>
                </section>

            </div>
        </div>
    </div>

    <div class="gerando-overlay" id="gerando-overlay" aria-hidden="true">
        <div class="gerando-card">
            <div class="gerando-spinner" aria-hidden="true"></div>
            <div class="gerando-titulo" id="gerando-titulo">Selecionando seu jogo...</div>
            <div class="gerando-texto" id="gerando-texto">Aguarde enquanto preparamos sua combinação.</div>
        </div>
    </div>

    <style>
        .cupom-layout {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
            align-items: stretch;
        }

        .cupom-input-wrap,
        .cupom-button-wrap {
            width: 100%;
        }

        .cupom-input {
            min-height: 54px;
        }

        .btn-cupom-aplicar {
            min-height: 54px;
            font-weight: 600;
            font-size: 0.98rem;
            white-space: nowrap;
            line-height: 1.1;
            padding: 0.75rem 1rem;
        }

        .hero-card>.card-body {
            padding-top: 2rem !important;
            padding-bottom: 2rem !important;
        }

        .lottus-generator-card {
            margin-top: 0;
        }

        .lottus-base-alert {
            margin-top: 1rem;
        }

        .lottus-tab-panel {
            display: none !important;
        }

        .lottus-tab-panel.active {
            display: block !important;
        }

        .lottus-product-tabs {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            background: linear-gradient(180deg, #f8fbff 0%, #eef5ff 100%);
            padding: 0.32rem;
            border-radius: 16px;
            border: 1px solid #dbe7fb;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.85);
            margin-bottom: 1.15rem !important;
        }

        .lottus-tab-btn {
            flex: 1;
            border: 0;
            background: transparent;
            border-radius: 13px;
            padding: 0.55rem 0.65rem;
            min-height: 42px;
            font-weight: 700;
            font-size: 0.82rem;
            line-height: 1.2;
            color: #667085;
            transition: all .22s ease;
            cursor: pointer;
            outline: none;
        }

        .lottus-tab-btn:hover {
            background: rgba(255, 255, 255, 0.75);
            color: #22314a;
        }

        .lottus-tab-btn.active {
            background: linear-gradient(135deg, #0d6efd 0%, #3d8bfd 45%, #6ea8fe 100%);
            color: #ffffff;
            box-shadow: 0 10px 20px rgba(13, 110, 253, 0.16), inset 0 1px 0 rgba(255, 255, 255, 0.15);
        }

        .lottus-tab-btn.active:hover {
            color: #ffffff;
        }

        .fechamento-resumo {
            overflow: hidden;
        }

        .fechamento-resumo-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            column-gap: 0.85rem;
            margin-bottom: 0.55rem;
        }

        .fechamento-resumo-label {
            display: block;
            min-width: 0;
            line-height: 1.25;
            white-space: nowrap;
        }

        .fechamento-resumo-value {
            display: block;
            min-width: 0;
            max-width: 100%;
            text-align: right;
            line-height: 1.25;
            white-space: nowrap;
        }

        .fechamento-produto-title {
            letter-spacing: -0.01em;
        }

        .fechamento-submit-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 58px;
            line-height: 1.15;
            text-align: center;
        }

        .fechamento-submit-text-mobile {
            display: none;
        }

        @media (min-width: 768px) {
            .cupom-layout {
                grid-template-columns: minmax(0, 1fr) 140px;
                gap: 0.75rem;
            }
        }

        @media (max-width: 767.98px) {
            .lottus-product-tabs {
                gap: 0.3rem;
            }

            .lottus-tab-btn {
                font-size: 0.78rem;
                padding: 0.5rem 0.45rem;
            }

            .fechamento-resumo {
                padding: 1rem 1.15rem !important;
            }

            .fechamento-resumo-row {
                column-gap: 0.65rem;
                margin-bottom: 0.58rem;
            }

            .fechamento-resumo-label {
                font-size: 0.82rem;
            }

            .fechamento-resumo-value {
                font-size: 0.88rem;
            }

            .fechamento-produto-title {
                font-size: 0.84rem;
            }

            .fechamento-select {
                font-size: 0.96rem;
                padding-left: 1rem;
                padding-right: 2.6rem;
                min-height: 56px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                background-position: right 1rem center;
            }

            .fechamento-submit-btn {
                min-height: 58px;
                padding-top: 0.95rem !important;
                padding-bottom: 0.95rem !important;
                font-size: 1rem;
                white-space: nowrap;
            }

            .fechamento-submit-text-desktop {
                display: none;
            }

            .fechamento-submit-text-mobile {
                display: inline;
            }
        }

        @media (max-width: 390px) {
            .fechamento-resumo {
                padding-left: 1rem !important;
                padding-right: 1rem !important;
            }

            .fechamento-resumo-row {
                column-gap: 0.5rem;
            }

            .fechamento-resumo-label {
                font-size: 0.78rem;
            }

            .fechamento-resumo-value {
                font-size: 0.84rem;
            }

            .fechamento-produto-title {
                font-size: 0.80rem;
            }

            .fechamento-select {
                font-size: 0.90rem;
                padding-right: 2.4rem;
            }
        }

        @media (max-width: 360px) {
            .fechamento-resumo-label {
                font-size: 0.74rem;
            }

            .fechamento-resumo-value {
                font-size: 0.80rem;
            }

            .fechamento-produto-title {
                font-size: 0.76rem;
            }

            .fechamento-submit-btn {
                font-size: 0.95rem;
            }
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('form-gerar-jogo');
            const formFechamento = document.getElementById('form-gerar-fechamento');
            const quantidade = document.getElementById('quantidade');
            const emailInput = document.getElementById('email');
            const valorTotal = document.getElementById('valor-total');
            const subtotalPreview = document.getElementById('subtotal-preview');
            const descontoPreview = document.getElementById('desconto-preview');
            const valorUnitario = {{ json_encode((float) $valorUnitario) }};
            const botaoGerar = document.getElementById('btn-gerar-jogo');
            const botaoGerarFechamento = document.getElementById('btn-gerar-fechamento');
            const overlay = document.getElementById('gerando-overlay');
            const tituloLoading = document.getElementById('gerando-titulo');
            const textoLoading = document.getElementById('gerando-texto');
            const sidebar = document.getElementById('lottus-sidebar');
            const sidebarToggle = document.getElementById('lottus-sidebar-mobile-toggle');
            const sidebarBackdrop = document.getElementById('lottus-sidebar-backdrop');
            const sidebarLinks = document.querySelectorAll('.lottus-sidebar-link');
            const sections = document.querySelectorAll('.lottus-section-anchor');
            const cupomInput = document.getElementById('cupom');
            const cupomAplicadoInput = document.getElementById('cupom-aplicado');
            const btnValidarCupom = document.getElementById('btn-validar-cupom');
            const feedbackCupom = document.getElementById('cupom-feedback');
            const tabButtons = document.querySelectorAll('.lottus-tab-btn');
            const tabPanels = document.querySelectorAll('.lottus-tab-panel');
            const quantidadeFechamento = document.getElementById('quantidade-dezenas-fechamento');
            const fechamentoDezenasPreview = document.getElementById('fechamento-dezenas-preview');
            const fechamentoJogosPreview = document.getElementById('fechamento-jogos-preview');
            const fechamentoValorTotal = document.getElementById('fechamento-valor-total');
            const cupomFechamentoInput = document.getElementById('cupom-fechamento');
            const cupomAplicadoFechamentoInput = document.getElementById('cupom-aplicado-fechamento');
            const btnValidarCupomFechamento = document.getElementById('btn-validar-cupom-fechamento');
            const feedbackCupomFechamento = document.getElementById('cupom-feedback-fechamento');
            const fechamentoDescontoPreview = document.getElementById('fechamento-desconto-preview');
            const fechamentoSubtotalPreview = document.getElementById('fechamento-subtotal-preview');

            function formatarBRL(valor) {
                return 'R$ ' + Number(valor).toLocaleString('pt-BR', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }

            function obterQuantidadeSelecionada() {
                return parseInt(quantidade.value || 1, 10);
            }

            function subtotalAtual() {
                const qtd = obterQuantidadeSelecionada();
                return qtd * valorUnitario;
            }

            function atualizarResumoSemCupom() {
                const subtotal = subtotalAtual();

                subtotalPreview.textContent = formatarBRL(subtotal);
                descontoPreview.textContent = formatarBRL(0);
                valorTotal.textContent = formatarBRL(subtotal);
            }

            function obterFechamentoSelecionado() {
                if (!quantidadeFechamento) {
                    return {
                        dezenas: 16,
                        jogos: 16,
                        valor: 7.90
                    };
                }

                const selectedOption = quantidadeFechamento.options[quantidadeFechamento.selectedIndex];

                if (!selectedOption) {
                    return {
                        dezenas: 16,
                        jogos: 16,
                        valor: 7.90
                    };
                }

                return {
                    dezenas: parseInt(selectedOption.value || 16, 10),
                    jogos: parseInt(selectedOption.dataset.games || 16, 10),
                    valor: parseFloat(selectedOption.dataset.price || 0)
                };
            }

            function atualizarResumoFechamentoSemCupom() {
                const fechamento = obterFechamentoSelecionado();

                fechamentoDezenasPreview.textContent = `${fechamento.dezenas} dezenas`;
                fechamentoJogosPreview.textContent = fechamento.jogos === 1 ? '1 jogo' :
                `${fechamento.jogos} jogos`;
                fechamentoSubtotalPreview.textContent = formatarBRL(fechamento.valor);
                fechamentoDescontoPreview.textContent = formatarBRL(0);
                fechamentoValorTotal.textContent = formatarBRL(fechamento.valor);
            }

            function limparCupomAplicado() {
                cupomAplicadoInput.value = '';
                feedbackCupom.innerHTML = '';
                atualizarResumoSemCupom();
            }

            function limparCupomFechamentoAplicado() {
                cupomAplicadoFechamentoInput.value = '';
                feedbackCupomFechamento.innerHTML = '';
                atualizarResumoFechamentoSemCupom();
            }

            function atualizarTextoBotao() {
                const qtd = obterQuantidadeSelecionada();
                botaoGerar.textContent = qtd > 1 ? 'Selecionar jogos agora' : 'Selecionar jogo agora';
            }

            function atualizarResumoFechamento() {
                atualizarResumoFechamentoSemCupom();
            }

            function exibirLoading() {
                const qtd = obterQuantidadeSelecionada();

                if (qtd > 1) {
                    tituloLoading.textContent = 'Selecionando seus jogos...';
                    textoLoading.textContent = 'Aguarde enquanto preparamos suas combinações.';
                } else {
                    tituloLoading.textContent = 'Selecionando seu jogo...';
                    textoLoading.textContent = 'Aguarde enquanto preparamos sua combinação.';
                }

                overlay.classList.add('ativo');
                overlay.setAttribute('aria-hidden', 'false');

                botaoGerar.disabled = true;
                botaoGerar.classList.add('btn-loading');
                botaoGerar.textContent = qtd > 1 ? 'Selecionando jogos...' : 'Selecionando jogo...';
            }

            function exibirLoadingFechamento() {
                tituloLoading.textContent = 'Selecionando seu fechamento...';
                textoLoading.textContent = 'Aguarde enquanto a Lottus prepara seu fechamento inteligente.';

                overlay.classList.add('ativo');
                overlay.setAttribute('aria-hidden', 'false');

                botaoGerarFechamento.disabled = true;
                botaoGerarFechamento.classList.add('btn-loading');
                botaoGerarFechamento.textContent = 'Selecionando fechamento...';
            }

            function abrirSidebarMobile() {
                sidebar.classList.add('mobile-open');
                sidebarBackdrop.classList.add('ativo');
                document.body.classList.add('lottus-sidebar-open');
            }

            function fecharSidebarMobile() {
                sidebar.classList.remove('mobile-open');
                sidebarBackdrop.classList.remove('ativo');
                document.body.classList.remove('lottus-sidebar-open');
            }

            function ativarLink(hash) {
                sidebarLinks.forEach(function(link) {
                    link.classList.toggle('active', link.getAttribute('href') === hash);
                });
            }

            function activateTab(targetId) {
                tabButtons.forEach(function(button) {
                    button.classList.toggle('active', button.dataset.target === targetId);
                });

                tabPanels.forEach(function(panel) {
                    panel.classList.toggle('active', panel.id === targetId);
                });
            }

            async function validarCupom() {
                const codigo = (cupomInput?.value || '').trim();
                const qtd = obterQuantidadeSelecionada();
                const email = (emailInput?.value || '').trim();

                if (!codigo) {
                    feedbackCupom.innerHTML =
                        '<div class="alert alert-warning border-0 shadow-sm rounded-4 mt-2 mb-0">Digite um cupom para validar.</div>';
                    cupomAplicadoInput.value = '';
                    atualizarResumoSemCupom();
                    return;
                }

                btnValidarCupom.disabled = true;
                btnValidarCupom.textContent = 'Validando...';

                try {
                    const response = await fetch(@json(route('cupom.validar')), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': @json(csrf_token()),
                        },
                        body: JSON.stringify({
                            codigo,
                            quantidade: qtd,
                            email: email
                        }),
                    });

                    const data = await response.json();

                    if (!response.ok) {
                        feedbackCupom.innerHTML =
                            `<div class="alert alert-danger border-0 shadow-sm rounded-4 mt-2 mb-0">${data.mensagem || 'Não foi possível aplicar o cupom.'}</div>`;
                        cupomAplicadoInput.value = '';
                        atualizarResumoSemCupom();
                        return;
                    }

                    subtotalPreview.textContent = formatarBRL(data.subtotal);
                    descontoPreview.textContent = '- ' + formatarBRL(data.desconto);
                    valorTotal.textContent = formatarBRL(data.valor_final);
                    cupomAplicadoInput.value = data.codigo;

                    feedbackCupom.innerHTML =
                        `<div class="alert alert-success border-0 shadow-sm rounded-4 mt-2 mb-0"><strong>${data.descricao}</strong> aplicado com sucesso.</div>`;
                } catch (error) {
                    feedbackCupom.innerHTML =
                        '<div class="alert alert-danger border-0 shadow-sm rounded-4 mt-2 mb-0">Erro ao validar o cupom. Tente novamente.</div>';
                    cupomAplicadoInput.value = '';
                    atualizarResumoSemCupom();
                } finally {
                    btnValidarCupom.disabled = false;
                    btnValidarCupom.textContent = 'Aplicar';
                }
            }


            async function validarCupomFechamento() {
                const codigo = (cupomFechamentoInput?.value || '').trim();
                const email = (document.getElementById('email-fechamento')?.value || '').trim();
                const fechamento = obterFechamentoSelecionado();

                if (!codigo) {
                    feedbackCupomFechamento.innerHTML =
                        '<div class="alert alert-warning border-0 shadow-sm rounded-4 mt-2 mb-0">Digite um cupom para validar.</div>';
                    cupomAplicadoFechamentoInput.value = '';
                    atualizarResumoFechamentoSemCupom();
                    return;
                }

                btnValidarCupomFechamento.disabled = true;
                btnValidarCupomFechamento.textContent = 'Validando...';

                try {
                    const response = await fetch(@json(route('cupom.validar')), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': @json(csrf_token()),
                        },
                        body: JSON.stringify({
                            codigo,
                            email: email,
                            produto: 'fechamento',
                            tipo: 'fechamento',
                            quantidade: 1,
                            quantidade_dezenas: fechamento.dezenas,
                            jogos_entregues: fechamento.jogos,
                            subtotal: fechamento.valor,
                            valor_original: fechamento.valor
                        }),
                    });

                    const data = await response.json();

                    if (!response.ok) {
                        feedbackCupomFechamento.innerHTML =
                            `<div class="alert alert-danger border-0 shadow-sm rounded-4 mt-2 mb-0">${data.mensagem || 'Não foi possível aplicar o cupom.'}</div>`;
                        cupomAplicadoFechamentoInput.value = '';
                        atualizarResumoFechamentoSemCupom();
                        return;
                    }

                    const subtotal = Number(data.subtotal ?? fechamento.valor);
                    const desconto = Number(data.desconto ?? 0);
                    const valorFinal = Number(data.valor_final ?? Math.max(subtotal - desconto, 0));

                    fechamentoSubtotalPreview.textContent = formatarBRL(subtotal);
                    fechamentoDescontoPreview.textContent = '- ' + formatarBRL(desconto);
                    fechamentoValorTotal.textContent = formatarBRL(valorFinal);
                    cupomAplicadoFechamentoInput.value = data.codigo || codigo;

                    feedbackCupomFechamento.innerHTML =
                        `<div class="alert alert-success border-0 shadow-sm rounded-4 mt-2 mb-0"><strong>${data.descricao || 'Cupom'}</strong> aplicado com sucesso.</div>`;
                } catch (error) {
                    feedbackCupomFechamento.innerHTML =
                        '<div class="alert alert-danger border-0 shadow-sm rounded-4 mt-2 mb-0">Erro ao validar o cupom. Tente novamente.</div>';
                    cupomAplicadoFechamentoInput.value = '';
                    atualizarResumoFechamentoSemCupom();
                } finally {
                    btnValidarCupomFechamento.disabled = false;
                    btnValidarCupomFechamento.textContent = 'Aplicar';
                }
            }

            if (tabButtons.length) {
                tabButtons.forEach(function(button) {
                    button.addEventListener('click', function() {
                        activateTab(button.dataset.target);
                    });
                });

                activateTab('simple-game-panel');
            }

            if (quantidadeFechamento) {
                quantidadeFechamento.addEventListener('change', function() {
                    atualizarResumoFechamento();

                    if (cupomAplicadoFechamentoInput.value) {
                        validarCupomFechamento();
                    }
                });

                atualizarResumoFechamento();
            }

            if (quantidade && valorTotal) {
                quantidade.addEventListener('change', function() {
                    atualizarResumoSemCupom();
                    atualizarTextoBotao();

                    if (cupomAplicadoInput.value) {
                        validarCupom();
                    }
                });

                atualizarResumoSemCupom();
                atualizarTextoBotao();
            }

            if (emailInput) {
                emailInput.addEventListener('change', function() {
                    if (cupomAplicadoInput.value) {
                        validarCupom();
                    }
                });
            }

            if (cupomInput) {
                cupomInput.addEventListener('input', function() {
                    if (!cupomInput.value.trim()) {
                        limparCupomAplicado();
                    }
                });
            }

            if (cupomFechamentoInput) {
                cupomFechamentoInput.addEventListener('input', function() {
                    if (!cupomFechamentoInput.value.trim()) {
                        limparCupomFechamentoAplicado();
                    }
                });
            }

            if (btnValidarCupomFechamento) {
                btnValidarCupomFechamento.addEventListener('click', function() {
                    validarCupomFechamento();
                });
            }

            const emailFechamentoInput = document.getElementById('email-fechamento');

            if (emailFechamentoInput) {
                emailFechamentoInput.addEventListener('change', function() {
                    if (cupomAplicadoFechamentoInput.value) {
                        validarCupomFechamento();
                    }
                });
            }

            if (btnValidarCupom) {
                btnValidarCupom.addEventListener('click', function() {
                    validarCupom();
                });
            }

            if (form) {
                form.addEventListener('submit', function() {
                    exibirLoading();
                });
            }

            if (formFechamento) {
                formFechamento.addEventListener('submit', function() {
                    exibirLoadingFechamento();
                });
            }

            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function() {
                    if (sidebar.classList.contains('mobile-open')) {
                        fecharSidebarMobile();
                    } else {
                        abrirSidebarMobile();
                    }
                });
            }

            if (sidebarBackdrop) {
                sidebarBackdrop.addEventListener('click', function() {
                    fecharSidebarMobile();
                });
            }

            sidebarLinks.forEach(function(link) {
                link.addEventListener('click', function() {
                    ativarLink(link.getAttribute('href'));

                    if (window.innerWidth < 1200) {
                        fecharSidebarMobile();
                    }
                });
            });

            if (window.innerWidth >= 1200 && sidebar) {
                sidebar.addEventListener('mouseenter', function() {
                    sidebar.classList.add('expanded');
                });

                sidebar.addEventListener('mouseleave', function() {
                    sidebar.classList.remove('expanded');
                });
            }

            if (sections.length) {
                const observer = new IntersectionObserver(function(entries) {
                    entries.forEach(function(entry) {
                        if (entry.isIntersecting) {
                            ativarLink('#' + entry.target.id);
                        }
                    });
                }, {
                    root: null,
                    rootMargin: '-35% 0px -45% 0px',
                    threshold: 0.1
                });

                sections.forEach(function(section) {
                    observer.observe(section);
                });
            }

            if (cupomAplicadoInput.value) {
                validarCupom();
            }

            if (cupomAplicadoFechamentoInput.value) {
                validarCupomFechamento();
            }
        });
    </script>
@endsection