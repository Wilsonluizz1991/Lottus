<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | Lottus</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(37, 99, 235, 0.18), transparent 35%),
                radial-gradient(circle at bottom right, rgba(99, 102, 241, 0.16), transparent 30%),
                linear-gradient(135deg, #081225 0%, #0f172a 45%, #111827 100%);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #e5eefc;
        }

        .lottus-auth-page {
            min-height: 100vh;
            display: flex;
            align-items: stretch;
        }

        .lottus-auth-grid {
            width: 100%;
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
        }

        .lottus-auth-brand {
            position: relative;
            overflow: hidden;
            padding: 56px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border-right: 1px solid rgba(255, 255, 255, 0.08);
        }

        .lottus-auth-brand::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                linear-gradient(135deg, rgba(255,255,255,0.04), transparent 40%),
                radial-gradient(circle at 20% 20%, rgba(59,130,246,0.24), transparent 28%),
                radial-gradient(circle at 80% 70%, rgba(139,92,246,0.20), transparent 24%);
            pointer-events: none;
        }

        .lottus-auth-brand-inner,
        .lottus-auth-footer {
            position: relative;
            z-index: 1;
        }

        .lottus-kicker {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
            color: #cfe0ff;
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            backdrop-filter: blur(10px);
        }

        .lottus-brand-title {
            margin: 28px 0 12px;
            font-size: clamp(2.6rem, 4vw, 4.8rem);
            line-height: 0.95;
            font-weight: 900;
            letter-spacing: -0.04em;
            color: #ffffff;
        }

        .lottus-brand-subtitle {
            max-width: 720px;
            font-size: 1.15rem;
            line-height: 1.7;
            color: rgba(226, 232, 240, 0.88);
        }

        .lottus-brand-cards {
            margin-top: 44px;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
        }

        .lottus-brand-card {
            padding: 20px;
            border-radius: 24px;
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(10px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.18);
        }

        .lottus-brand-card-label {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #9fb8e8;
            margin-bottom: 10px;
            font-weight: 700;
        }

        .lottus-brand-card-value {
            font-size: 1.55rem;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 8px;
        }

        .lottus-brand-card-text {
            font-size: 0.96rem;
            line-height: 1.55;
            color: rgba(226, 232, 240, 0.8);
        }

        .lottus-auth-footer {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: center;
            margin-top: 36px;
            color: rgba(203, 213, 225, 0.78);
            font-size: 0.95rem;
        }

        .lottus-auth-footer a {
            color: #cfe0ff;
            text-decoration: none;
            font-weight: 700;
        }

        .lottus-auth-panel {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px;
        }

        .lottus-auth-card {
            width: 100%;
            max-width: 500px;
            padding: 36px;
            border-radius: 32px;
            background: rgba(9, 16, 30, 0.78);
            border: 1px solid rgba(255, 255, 255, 0.09);
            box-shadow: 0 30px 70px rgba(0, 0, 0, 0.32);
            backdrop-filter: blur(18px);
        }

        .lottus-auth-logo {
            width: 62px;
            height: 62px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #2563eb 0%, #4f46e5 100%);
            color: #fff;
            font-size: 1.5rem;
            font-weight: 900;
            box-shadow: 0 16px 30px rgba(37, 99, 235, 0.35);
        }

        .lottus-auth-heading {
            margin-top: 22px;
            margin-bottom: 10px;
            font-size: 2rem;
            line-height: 1.1;
            font-weight: 900;
            color: #ffffff;
        }

        .lottus-auth-description {
            margin: 0 0 28px;
            color: rgba(226, 232, 240, 0.76);
            line-height: 1.65;
            font-size: 0.98rem;
        }

        .lottus-alert {
            border-radius: 18px;
            padding: 14px 16px;
            margin-bottom: 18px;
            font-size: 0.95rem;
            line-height: 1.5;
            border: 1px solid transparent;
        }

        .lottus-alert-success {
            background: rgba(34, 197, 94, 0.12);
            color: #bbf7d0;
            border-color: rgba(34, 197, 94, 0.24);
        }

        .lottus-alert-error {
            background: rgba(239, 68, 68, 0.12);
            color: #fecaca;
            border-color: rgba(239, 68, 68, 0.24);
        }

        .lottus-field {
            margin-bottom: 18px;
        }

        .lottus-label {
            display: block;
            margin-bottom: 8px;
            font-size: 0.92rem;
            font-weight: 700;
            color: #dbeafe;
        }

        .lottus-input {
            width: 100%;
            height: 56px;
            border-radius: 18px;
            border: 1px solid rgba(148, 163, 184, 0.24);
            background: rgba(255, 255, 255, 0.06);
            color: #ffffff;
            padding: 0 18px;
            font-size: 1rem;
            outline: none;
            transition: all 0.2s ease;
        }

        .lottus-input::placeholder {
            color: rgba(203, 213, 225, 0.55);
        }

        .lottus-input:focus {
            border-color: rgba(96, 165, 250, 0.78);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.18);
            background: rgba(255, 255, 255, 0.09);
        }

        .lottus-error-text {
            margin-top: 8px;
            font-size: 0.86rem;
            color: #fca5a5;
        }

        .lottus-auth-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            margin-top: 8px;
            margin-bottom: 22px;
        }

        .lottus-checkbox {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 0.94rem;
            color: rgba(226, 232, 240, 0.82);
            cursor: pointer;
        }

        .lottus-checkbox input {
            width: 18px;
            height: 18px;
            accent-color: #2563eb;
        }

        .lottus-link {
            color: #93c5fd;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.92rem;
        }

        .lottus-link:hover {
            color: #bfdbfe;
        }

        .lottus-submit {
            width: 100%;
            height: 58px;
            border: 0;
            border-radius: 18px;
            background: linear-gradient(135deg, #2563eb 0%, #4f46e5 100%);
            color: #ffffff;
            font-size: 1rem;
            font-weight: 800;
            letter-spacing: 0.01em;
            cursor: pointer;
            transition: transform 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease;
            box-shadow: 0 20px 35px rgba(37, 99, 235, 0.28);
        }

        .lottus-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 24px 40px rgba(37, 99, 235, 0.35);
        }

        .lottus-submit:active {
            transform: translateY(0);
        }

        .lottus-auth-note {
            margin-top: 20px;
            text-align: center;
            color: rgba(203, 213, 225, 0.72);
            font-size: 0.92rem;
            line-height: 1.6;
        }

        .lottus-auth-note a {
            color: #dbeafe;
            text-decoration: none;
            font-weight: 700;
        }

        @media (max-width: 1100px) {
            .lottus-auth-grid {
                grid-template-columns: 1fr;
            }

            .lottus-auth-brand {
                padding: 32px 24px 24px;
                border-right: 0;
                border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            }

            .lottus-brand-cards {
                grid-template-columns: 1fr;
            }

            .lottus-auth-footer {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 640px) {
            .lottus-auth-panel {
                padding: 20px;
            }

            .lottus-auth-card {
                padding: 24px;
                border-radius: 24px;
            }

            .lottus-auth-brand {
                padding: 28px 20px 20px;
            }

            .lottus-brand-title {
                font-size: 2.6rem;
            }

            .lottus-auth-actions {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="lottus-auth-page">
        <div class="lottus-auth-grid">
            <section class="lottus-auth-brand">
                <div class="lottus-auth-brand-inner">
                    <span class="lottus-kicker">Área administrativa</span>

                    <h1 class="lottus-brand-title">Lottus</h1>

                    <p class="lottus-brand-subtitle">
                        Acesse o painel do sistema para acompanhar vendas, descontos, cupons,
                        pagamentos e a evolução comercial da sua operação com uma experiência
                        visual alinhada ao posicionamento premium do Lottus.
                    </p>

                    <div class="lottus-brand-cards">
                        <div class="lottus-brand-card">
                            <div class="lottus-brand-card-label">Gestão</div>
                            <div class="lottus-brand-card-value">Pedidos</div>
                            <div class="lottus-brand-card-text">
                                Visualize vendas, pagamentos aprovados, pendências e status de cada pedido.
                            </div>
                        </div>

                        <div class="lottus-brand-card">
                            <div class="lottus-brand-card-label">Performance</div>
                            <div class="lottus-brand-card-value">Receita</div>
                            <div class="lottus-brand-card-text">
                                Acompanhe faturamento bruto, descontos aplicados e resultado líquido.
                            </div>
                        </div>

                        <div class="lottus-brand-card">
                            <div class="lottus-brand-card-label">Marketing</div>
                            <div class="lottus-brand-card-value">Cupons</div>
                            <div class="lottus-brand-card-text">
                                Controle campanhas promocionais e regras de desconto com mais precisão.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="lottus-auth-footer">
                    <div>
                        Plataforma inteligente para geração e comercialização de jogos da Lotofácil.
                    </div>

                    <a href="{{ route('home') }}">Voltar para o site</a>
                </div>
            </section>

            <section class="lottus-auth-panel">
                <div class="lottus-auth-card">
                    <div class="lottus-auth-logo">L</div>

                    <h2 class="lottus-auth-heading">Entrar no sistema</h2>

                    <p class="lottus-auth-description">
                        Faça login para acessar a área administrativa do Lottus e gerenciar
                        a operação com segurança.
                    </p>

                    @if (session('status'))
                        <div class="lottus-alert lottus-alert-success">
                            {{ session('status') }}
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="lottus-alert lottus-alert-error">
                            Verifique os dados informados e tente novamente.
                        </div>
                    @endif

                    <form method="POST" action="{{ route('login') }}">
                        @csrf

                        <div class="lottus-field">
                            <label for="email" class="lottus-label">E-mail</label>
                            <input
                                id="email"
                                class="lottus-input"
                                type="email"
                                name="email"
                                value="{{ old('email') }}"
                                placeholder="voce@exemplo.com"
                                required
                                autofocus
                                autocomplete="username"
                            >
                            @error('email')
                                <div class="lottus-error-text">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="lottus-field">
                            <label for="password" class="lottus-label">Senha</label>
                            <input
                                id="password"
                                class="lottus-input"
                                type="password"
                                name="password"
                                placeholder="Digite sua senha"
                                required
                                autocomplete="current-password"
                            >
                            @error('password')
                                <div class="lottus-error-text">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="lottus-auth-actions">
                            <label for="remember_me" class="lottus-checkbox">
                                <input id="remember_me" type="checkbox" name="remember">
                                <span>Manter conectado</span>
                            </label>

                            @if (Route::has('password.request'))
                                <a class="lottus-link" href="{{ route('password.request') }}">
                                    Esqueci minha senha
                                </a>
                            @endif
                        </div>

                        <button type="submit" class="lottus-submit">
                            Entrar na área administrativa
                        </button>

                        <div class="lottus-auth-note">
                            Acesso restrito aos usuários autorizados do sistema Lottus.
                            <br>
                            <a href="{{ route('home') }}">Ir para a página pública</a>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </div>
</body>
</html>