@extends('layouts.app')

@section('content')
<div class="container py-4">
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <h1 class="h3 mb-3">Lottus</h1>
                    <p class="text-muted mb-4">
                        Gerador inteligente de apostas da Lotofácil com equilíbrio, quentes, atrasadas,
                        frequência histórica, frequência recente e filtros anti-popularidade.
                    </p>

                    @if(session('sucesso'))
                        <div class="alert alert-success">
                            {{ session('sucesso') }}
                        </div>
                    @endif

                    @if($errors->any())
                        <div class="alert alert-danger">
                            <strong>Ocorreram erros:</strong>
                            <ul class="mb-0 mt-2">
                                @foreach($errors->all() as $erro)
                                    <li>{{ $erro }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="alert alert-info">
                        <div><strong>Último concurso registrado:</strong> {{ $ultimoConcurso->concurso }}</div>
                        <div><strong>Data do último sorteio:</strong> {{ optional($ultimoConcurso->data_sorteio)->format('d/m/Y') }}</div>
                        <div><strong>Próximo concurso esperado:</strong> {{ $proximoConcurso }}</div>
                        @if(!empty($dataEsperada))
                            <div><strong>Próxima data prevista de sorteio:</strong> {{ $dataEsperada->format('d/m/Y') }}</div>
                        @endif
                    </div>

                    <form method="POST" action="{{ route('lottus.gerar-aposta') }}">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-lg">
                            Gerar aposta
                        </button>
                    </form>
                </div>
            </div>

            @php
                $aposta = null;

                if (session('aposta_id')) {
                    $aposta = \App\Models\LotofacilAposta::with('concursoBase')->find(session('aposta_id'));
                } elseif (!empty($mostrarAposta) && !empty($apostaDoDia)) {
                    $aposta = $apostaDoDia;
                }
            @endphp

            @if($aposta)
                <div class="card shadow-sm border-0 mt-4">
                    <div class="card-body p-4">
                        <h2 class="h4 mb-3">Aposta do dia</h2>

                        <div class="mb-4">
                            <div class="d-flex flex-wrap gap-2">
                                @foreach($aposta->dezenas as $dezena)
                                    <span class="badge rounded-pill text-bg-primary px-3 py-2 fs-6">
                                        {{ str_pad($dezena, 2, '0', STR_PAD_LEFT) }}
                                    </span>
                                @endforeach
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="border rounded p-3 bg-light h-100">
                                    <small class="text-muted d-block">Score</small>
                                    <strong>{{ number_format($aposta->score, 2, ',', '.') }}</strong>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="border rounded p-3 bg-light h-100">
                                    <small class="text-muted d-block">Pares / Ímpares</small>
                                    <strong>{{ $aposta->pares }} / {{ $aposta->impares }}</strong>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="border rounded p-3 bg-light h-100">
                                    <small class="text-muted d-block">Soma</small>
                                    <strong>{{ $aposta->soma }}</strong>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="border rounded p-3 bg-light h-100">
                                    <small class="text-muted d-block">Quentes</small>
                                    <strong>{{ $aposta->quentes }}</strong>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="border rounded p-3 bg-light h-100">
                                    <small class="text-muted d-block">Atrasadas</small>
                                    <strong>{{ $aposta->atrasadas }}</strong>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="border rounded p-3 bg-light h-100">
                                    <small class="text-muted d-block">Repetidas do último concurso</small>
                                    <strong>{{ $aposta->repetidas_ultimo_concurso }}</strong>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <p class="mb-1">
                            <strong>Concurso base:</strong>
                            {{ $aposta->concursoBase->concurso ?? '-' }}
                        </p>
                        <p class="mb-0">
                            <strong>Data do sorteio base:</strong>
                            {{ optional($aposta->concursoBase->data_sorteio)->format('d/m/Y') }}
                        </p>
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <h2 class="h5 mb-3">Como funciona</h2>
                    <ul class="mb-0 ps-3">
                        <li>O sistema usa o último concurso registrado no banco.</li>
                        <li>O próximo concurso é definido por sequência numérica.</li>
                        <li>Antes de gerar, ele valida se existe concurso pendente para coleta.</li>
                        <li>Se houver pendência, solicita o novo concurso.</li>
                        <li>Se não houver pendência, gera a aposta diretamente.</li>
                        <li>É permitido apenas 1 jogo por dia.</li>
                        <li>As análises seguem a ordem dos concursos.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalResultado" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" action="{{ route('lottus.salvar-resultado-e-gerar') }}">
                @csrf

                <div class="modal-header">
                    <h5 class="modal-title">
                        Informe o resultado do concurso {{ $proximoConcurso }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Concurso</label>
                            <input
                                type="number"
                                class="form-control"
                                value="{{ $proximoConcurso }}"
                                disabled
                            >
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Data Sorteio</label>
                            <input
                                type="date"
                                name="data_sorteio"
                                class="form-control"
                                value="{{ old('data_sorteio', !empty($dataEsperada) ? $dataEsperada->format('Y-m-d') : '') }}"
                                required
                            >
                        </div>

                        <div class="col-12">
                            <hr class="my-1">
                            <h6 class="mb-0">Dezenas sorteadas</h6>
                        </div>

                        @for($i = 1; $i <= 15; $i++)
                            <div class="col-6 col-md-2">
                                <label class="form-label">Bola {{ $i }}</label>
                                <input
                                    type="number"
                                    name="bola{{ $i }}"
                                    class="form-control"
                                    min="1"
                                    max="25"
                                    value="{{ old('bola'.$i) }}"
                                    required
                                >
                            </div>
                        @endfor
                    </div>
                </div>

                <div class="modal-footer">
                    <button class="btn btn-primary" type="submit">
                        Salvar resultado e gerar aposta
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@if($mostrarModalResultado || $errors->any())
<script>
    window.addEventListener('load', function () {
        const modalElement = document.getElementById('modalResultado');

        if (modalElement && window.bootstrap) {
            const modal = new window.bootstrap.Modal(modalElement);
            modal.show();
        }
    });
</script>
@endif
@endsection