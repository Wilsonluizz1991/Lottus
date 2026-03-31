<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Retorno do pagamento</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 30px auto; padding: 0 16px; }
        .card { border: 1px solid #ddd; border-radius: 10px; padding: 20px; }
        .success { color: #027a48; }
        .failure { color: #b42318; }
        .pending { color: #b54708; }
        pre { background: #111; color: #f8f8f2; padding: 16px; border-radius: 10px; overflow: auto; }
        a.btn { display: inline-block; padding: 10px 16px; background: #eee; color: #111; border-radius: 8px; text-decoration: none; }
    </style>
</head>
<body>
    <div class="card">
        <h1 class="{{ $type }}">Retorno: {{ strtoupper($type) }}</h1>

        @if ($payment)
            <p><strong>Pagamento local:</strong> #{{ $payment->id }}</p>
            <p><strong>External reference:</strong> {{ $payment->external_reference }}</p>
            <p><strong>Status local:</strong> {{ $payment->local_status }}</p>
            <p><strong>Status MP:</strong> {{ $payment->provider_status ?: '-' }}</p>
            <p><strong>Payment ID:</strong> {{ $payment->provider_payment_id ?: '-' }}</p>

            <p>
                <a class="btn" href="{{ route('mercado-pago.show', $payment->id) }}">Ver pagamento</a>
            </p>
        @else
            <p>Não foi possível vincular o retorno a um pagamento local.</p>
        @endif

        <h2>Query recebida</h2>
        <pre>{{ json_encode($query, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
</body>
</html>