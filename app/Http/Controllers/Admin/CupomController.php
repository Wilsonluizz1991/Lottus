<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cupom;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CupomController extends Controller
{
    public function index()
    {
        $cupons = Cupom::query()
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('admin.cupons.index', [
            'cupons' => $cupons,
        ]);
    }

    public function create()
    {
        return view('admin.cupons.create');
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        Cupom::create($data);

        return redirect()
            ->route('admin.cupons.index')
            ->with('success', 'Cupom criado com sucesso.');
    }

    public function edit(Cupom $cupom)
    {
        return view('admin.cupons.edit', [
            'cupom' => $cupom,
        ]);
    }

    public function update(Request $request, Cupom $cupom)
    {
        $data = $this->validateData($request, $cupom->id);

        $cupom->update($data);

        return redirect()
            ->route('admin.cupons.index')
            ->with('success', 'Cupom atualizado com sucesso.');
    }

    public function destroy(Cupom $cupom)
    {
        $cupom->delete();

        return redirect()
            ->route('admin.cupons.index')
            ->with('success', 'Cupom removido com sucesso.');
    }

    private function validateData(Request $request, ?int $cupomId = null): array
    {
        $data = $request->validate([
            'codigo' => [
                'required',
                'string',
                'max:50',
                Rule::unique('cupons', 'codigo')->ignore($cupomId),
            ],
            'nome' => ['nullable', 'string', 'max:255'],
            'tipo_desconto' => ['required', Rule::in(['percentual', 'fixo'])],
            'valor_desconto' => ['required', 'numeric', 'min:0'],
            'valor_minimo_pedido' => ['nullable', 'numeric', 'min:0'],
            'limite_total_uso' => ['nullable', 'integer', 'min:1'],
            'limite_uso_por_email' => ['nullable', 'integer', 'min:1'],
            'inicio_em' => ['nullable', 'date'],
            'expira_em' => ['nullable', 'date', 'after_or_equal:inicio_em'],
            'observacoes' => ['nullable', 'string'],
            'ativo' => ['nullable', 'boolean'],
        ]);

        $data['codigo'] = mb_strtoupper(trim($data['codigo']));
        $data['ativo'] = $request->boolean('ativo');

        return $data;
    }
}