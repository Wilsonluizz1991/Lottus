<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name', 'Lottus') }}</title>

    <!-- DATA LAYER INICIAL -->
    <script>
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push({
            event: 'page_load',
            page_type: '{{ request()->path() }}',
            user_logged: {{ auth()->check() ? 'true' : 'false' }}
        });
    </script>

    <!-- GOOGLE TAG MANAGER -->
    <script>
        (function(w,d,s,l,i){
            w[l]=w[l]||[];
            w[l].push({'gtm.start': new Date().getTime(), event:'gtm.js'});
            var f=d.getElementsByTagName(s)[0],
                j=d.createElement(s),
                dl=l!='dataLayer'?'&l='+l:'';
            j.async=true;
            j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;
            f.parentNode.insertBefore(j,f);
        })(window,document,'script','dataLayer','GTM-T5GGGMP');
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="bg-light">

    <!-- GOOGLE TAG MANAGER (noscript) -->
    <noscript>
        <iframe src="https://www.googletagmanager.com/ns.html?id=GTM-T5GGGMP"
                height="0"
                width="0"
                style="display:none;visibility:hidden">
        </iframe>
    </noscript>

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