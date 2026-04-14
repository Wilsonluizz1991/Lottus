<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LottusPedido;
use Illuminate\Http\Request;

class PedidoController extends Controller
{
    public function index(Request $request)
    {
        $query = LottusPedido::with(['cupom', 'concursoBase'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('email')) {
            $query->where('email', 'like', '%' . $request->email . '%');
        }

        if ($request->filled('data_inicio')) {
            $query->whereDate('created_at', '>=', $request->data_inicio);
        }

        if ($request->filled('data_fim')) {
            $query->whereDate('created_at', '<=', $request->data_fim);
        }

        $pedidos = $query->paginate(20)->withQueryString();

        return view('admin.pedidos.index', [
            'pedidos' => $pedidos,
            'filtros' => $request->only([
                'status',
                'email',
                'data_inicio',
                'data_fim',
            ]),
        ]);
    }
}