<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Pagamento #{{ $payment->id }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; max-width: 1000px; margin: 30px auto; padding: 0 16px; }
        .card { border: 1px solid #ddd; border-radius: 10px; padding: 20px; margin-bottom: 20px; }
        .actions { display: flex; gap: 12px; flex-wrap: wrap; }
        button, a.btn { display: inline-block; padding: 10px 16px; border: none; border-radius: 8px; text-decoration: none; cursor: pointer; }
        button { background: #111; color: #fff; }
        a.btn { background: #eee; color: #111; }
        pre { background: #111; color: #f8f8f2; padding: 16px; border-radius: 10px; overflow: auto; }
        .msg { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; background: #ecfdf3; color: #027a48; }
    </style>
</head>
<body>
    <h1>Pagamento #{{ $payment->id }}</h1>

    @if (session('success'))
        <div class="msg">{{ session('success') }}</div>
    @endif

    <div class="card">
        <p><strong>External reference:</strong> {{ $payment->external_reference }}</p>
        <p><strong>Order code:</strong> {{ $payment->order_code ?: '-' }}</p>
        <p><strong>Valor:</strong> R$ {{ number_format((float) $payment->amount, 2, ',', '.') }}</p>
        <p><strong>Status local:</strong> {{ $payment->local_status }}</p>
        <p><strong>Status Mercado Pago:</strong> {{ $payment->provider_status ?: '-' }}</p>
        <p><strong>Status detail:</strong> {{ $payment->provider_status_detail ?: '-' }}</p>
        <p><strong>Preference ID:</strong> {{ $payment->provider_preference_id ?: '-' }}</p>
        <p><strong>Payment ID:</strong> {{ $payment->provider_payment_id ?: '-' }}</p>
        <p><strong>Payer email:</strong> {{ $payment->payer_email ?: '-' }}</p>
        <p><strong>Última sincronização:</strong> {{ optional($payment->last_synced_at)->format('d/m/Y H:i:s') ?: '-' }}</p>

        <div class="actions">
            <a class="btn" href="{{ route('mercado-pago.form') }}">Voltar</a>
            <a class="btn" href="{{ route('mercado-pago.checkout', $payment->id) }}">Abrir checkout</a>

            <form action="{{ route('mercado-pago.sync', $payment->id) }}" method="POST">
                @csrf
                <button type="submit">Sincronizar status</button>
            </form>
        </div>
    </div>

    <div class="card">
        <h2>Itens</h2>
        <pre>{{ json_encode($payment->items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>

    <div class="card">
        <h2>Resposta da preferência</h2>
        <pre>{{ json_encode($payment->preference_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>

    <div class="card">
        <h2>Última resposta de pagamento</h2>
        <pre>{{ json_encode($payment->last_payment_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
</body>
</html>