<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name', 'Lottus') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold" href="{{ route('home') }}">Lottus</a>

            @auth
                <div class="ms-auto d-flex align-items-center gap-3">
                    <span class="text-white small">{{ auth()->user()->name }}</span>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="btn btn-outline-light btn-sm" type="submit">Sair</button>
                    </form>
                </div>
            @endauth
        </div>
    </nav>

    <main class="py-4">
        @yield('content')
    </main>
</body>
</html>