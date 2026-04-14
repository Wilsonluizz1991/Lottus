@extends('admin.layout')

@section('admin_page')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h2 fw-bold mb-1">Editar cupom</h1>
        <p class="text-muted mb-0">Atualize as regras do cupom {{ $cupom->codigo }}.</p>
    </div>

    <a href="{{ route('admin.cupons.index') }}" class="btn btn-outline-secondary rounded-4">
        Voltar
    </a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <form method="POST" action="{{ route('admin.cupons.update', $cupom) }}">
            @csrf
            @method('PUT')

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Código</label>
                    <input type="text" name="codigo" class="form-control rounded-4" value="{{ old('codigo', $cupom->codigo) }}" required>
                    @error('codigo') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-8">
                    <label class="form-label fw-semibold">Nome</label>
                    <input type="text" name="nome" class="form-control rounded-4" value="{{ old('nome', $cupom->nome) }}">
                    @error('nome') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Tipo</label>
                    <select name="tipo_desconto" class="form-select rounded-4" required>
                        <option value="percentual" {{ old('tipo_desconto', $cupom->tipo_desconto) === 'percentual' ? 'selected' : '' }}>Percentual</option>
                        <option value="fixo" {{ old('tipo_desconto', $cupom->tipo_desconto) === 'fixo' ? 'selected' : '' }}>Fixo</option>
                    </select>
                    @error('tipo_desconto') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Valor do desconto</label>
                    <input type="number" step="0.01" min="0" name="valor_desconto" class="form-control rounded-4" value="{{ old('valor_desconto', $cupom->valor_desconto) }}" required>
                    @error('valor_desconto') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Pedido mínimo</label>
                    <input type="number" step="0.01" min="0" name="valor_minimo_pedido" class="form-control rounded-4" value="{{ old('valor_minimo_pedido', $cupom->valor_minimo_pedido) }}">
                    @error('valor_minimo_pedido') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Ativo</label>
                    <select name="ativo" class="form-select rounded-4">
                        <option value="1" {{ old('ativo', $cupom->ativo ? '1' : '0') == '1' ? 'selected' : '' }}>Sim</option>
                        <option value="0" {{ old('ativo', $cupom->ativo ? '1' : '0') == '0' ? 'selected' : '' }}>Não</option>
                    </select>
                    @error('ativo') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Limite total de uso</label>
                    <input type="number" min="1" name="limite_total_uso" class="form-control rounded-4" value="{{ old('limite_total_uso', $cupom->limite_total_uso) }}">
                    @error('limite_total_uso') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Limite por e-mail</label>
                    <input type="number" min="1" name="limite_uso_por_email" class="form-control rounded-4" value="{{ old('limite_uso_por_email', $cupom->limite_uso_por_email) }}">
                    @error('limite_uso_por_email') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Início</label>
                    <input type="datetime-local" name="inicio_em" class="form-control rounded-4" value="{{ old('inicio_em', optional($cupom->inicio_em)->format('Y-m-d\TH:i')) }}">
                    @error('inicio_em') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Expira em</label>
                    <input type="datetime-local" name="expira_em" class="form-control rounded-4" value="{{ old('expira_em', optional($cupom->expira_em)->format('Y-m-d\TH:i')) }}">
                    @error('expira_em') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Observações</label>
                    <textarea name="observacoes" rows="4" class="form-control rounded-4">{{ old('observacoes', $cupom->observacoes) }}</textarea>
                    @error('observacoes') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary rounded-4 px-4">Salvar alterações</button>
                <a href="{{ route('admin.cupons.index') }}" class="btn btn-outline-secondary rounded-4 px-4">Cancelar</a>
            </div>
        </form>
    </div>
</div>
@endsection