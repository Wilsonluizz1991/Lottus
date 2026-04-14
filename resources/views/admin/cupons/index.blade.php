@extends('admin.layout')

@section('admin_page')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h2 fw-bold mb-1">Cupons</h1>
        <p class="text-muted mb-0">Crie, edite e remova cupons de desconto.</p>
    </div>

    <a href="{{ route('admin.cupons.create') }}" class="btn btn-primary rounded-4">
        Novo cupom
    </a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nome</th>
                        <th>Tipo</th>
                        <th>Valor</th>
                        <th>Usos</th>
                        <th>Status</th>
                        <th>Validade</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($cupons as $cupom)
                        <tr>
                            <td class="fw-bold">{{ $cupom->codigo }}</td>
                            <td>{{ $cupom->nome ?: '—' }}</td>
                            <td>{{ $cupom->tipo_desconto === 'percentual' ? 'Percentual' : 'Fixo' }}</td>
                            <td>
                                @if($cupom->tipo_desconto === 'percentual')
                                    {{ number_format($cupom->valor_desconto, 2, ',', '.') }}%
                                @else
                                    R$ {{ number_format($cupom->valor_desconto, 2, ',', '.') }}
                                @endif
                            </td>
                            <td>{{ $cupom->total_usos }}</td>
                            <td>
                                @if($cupom->ativo)
                                    <span class="badge text-bg-success">Ativo</span>
                                @else
                                    <span class="badge text-bg-secondary">Inativo</span>
                                @endif
                            </td>
                            <td>
                                @if($cupom->inicio_em || $cupom->expira_em)
                                    {{ $cupom->inicio_em?->format('d/m/Y H:i') ?: '—' }}
                                    <br>
                                    até
                                    <br>
                                    {{ $cupom->expira_em?->format('d/m/Y H:i') ?: '—' }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('admin.cupons.edit', $cupom) }}" class="btn btn-sm btn-outline-primary rounded-4">
                                    Editar
                                </a>

                                <form action="{{ route('admin.cupons.destroy', $cupom) }}"
                                      method="POST"
                                      class="d-inline-block"
                                      onsubmit="return confirm('Tem certeza que deseja remover este cupom?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-4">
                                        Excluir
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">Nenhum cupom cadastrado.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">
            {{ $cupons->links() }}
        </div>
    </div>
</div>
@endsection