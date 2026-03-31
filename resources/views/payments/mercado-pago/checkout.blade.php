<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Checkout Mercado Pago</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; max-width: 1000px; margin: 30px auto; padding: 0 16px; }
        .card { border: 1px solid #ddd; border-radius: 10px; padding: 20px; margin-bottom: 20px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .full { grid-column: 1 / -1; }
        label { display: block; margin-bottom: 6px; font-weight: bold; }
        input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 8px; }
        button, a.btn { display: inline-block; padding: 10px 16px; border: none; border-radius: 8px; text-decoration: none; cursor: pointer; }
        button { background: #111; color: #fff; }
        a.btn { background: #eee; color: #111; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #eee; padding: 10px; text-align: left; }
        .msg { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
        .success { background: #ecfdf3; color: #027a48; }
        .error { background: #fef3f2; color: #b42318; }
    </style>
</head>
<body>
    <h1>Mercado Pago - Checkout Local</h1>

    @if (session('success'))
        <div class="msg success">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="msg error">{{ session('error') }}</div>
    @endif

    @if ($errors->any())
        <div class="msg error">
            <strong>Erros:</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card">
        <h2>Criar pagamento</h2>

        <form action="{{ route('mercado-pago.create') }}" method="POST">
            @csrf

            <div class="grid">
                <div class="full">
                    <label for="title">Título</label>
                    <input type="text" name="title" id="title" value="{{ old('title', 'Assinatura Lottus') }}" required>
                </div>

                <div>
                    <label for="quantity">Quantidade</label>
                    <input type="number" name="quantity" id="quantity" value="{{ old('quantity', 1) }}" min="1" required>
                </div>

                <div>
                    <label for="unit_price">Valor unitário</label>
                    <input type="number" step="0.01" name="unit_price" id="unit_price" value="{{ old('unit_price', '19.90') }}" required>
                </div>

                <div>
                    <label for="payer_name">Nome do pagador</label>
                    <input type="text" name="payer_name" id="payer_name" value="{{ old('payer_name') }}">
                </div>

                <div>
                    <label for="payer_email">E-mail do pagador</label>
                    <input type="email" name="payer_email" id="payer_email" value="{{ old('payer_email') }}">
                </div>

                <div class="full">
                    <label for="order_code">Código do pedido</label>
                    <input type="text" name="order_code" id="order_code" value="{{ old('order_code') }}">
                </div>
            </div>

            <br>
            <button type="submit">Criar preferência</button>
        </form>
    </div>

    <div class="card">
        <h2>Pagamentos recentes</h2>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Referência</th>
                    <th>Valor</th>
                    <th>Status local</th>
                    <th>Status MP</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($payments as $payment)
                    <tr>
                        <td>{{ $payment->id }}</td>
                        <td>{{ $payment->external_reference }}</td>
                        <td>R$ {{ number_format((float) $payment->amount, 2, ',', '.') }}</td>
                        <td>{{ $payment->local_status }}</td>
                        <td>{{ $payment->provider_status ?: '-' }}</td>
                        <td>
                            <a class="btn" href="{{ route('mercado-pago.show', $payment->id) }}">Ver</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">Nenhum pagamento criado ainda.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</body>
</html>