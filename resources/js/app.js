import './bootstrap';
import * as bootstrap from 'bootstrap';
import 'bootstrap/dist/css/bootstrap.min.css';

window.bootstrap = bootstrap;

/*
|--------------------------------------------------------------------------
| LOTTUS TRACKING LAYER
|--------------------------------------------------------------------------
| Primeira camada de Growth Analytics
| Não altera UI
| Não depende ainda de GTM
| Já prepara Google Ads + Meta + GA4
|--------------------------------------------------------------------------
*/

window.dataLayer = window.dataLayer || [];

window.lottusTrack = function (eventName, payload = {}) {
    window.dataLayer.push({
        event: eventName,
        timestamp: new Date().toISOString(),
        page: window.location.pathname,
        ...payload
    });

    console.log('[Lottus Tracking]', eventName, payload);
};

/*
|--------------------------------------------------------------------------
| PAGE VIEW
|--------------------------------------------------------------------------
*/

document.addEventListener('DOMContentLoaded', () => {
    window.lottusTrack('page_view', {
        title: document.title
    });
});

/*
|--------------------------------------------------------------------------
| SCROLL DEPTH TRACKING
|--------------------------------------------------------------------------
*/

(function () {
    let scroll25 = false;
    let scroll50 = false;
    let scroll75 = false;
    let scroll90 = false;

    window.addEventListener('scroll', () => {
        const scrollTop = window.scrollY;
        const docHeight = document.documentElement.scrollHeight - window.innerHeight;

        if (docHeight <= 0) return;

        const percent = Math.round((scrollTop / docHeight) * 100);

        if (percent >= 25 && !scroll25) {
            scroll25 = true;

            window.lottusTrack('scroll_depth', {
                percent: 25
            });
        }

        if (percent >= 50 && !scroll50) {
            scroll50 = true;

            window.lottusTrack('scroll_depth', {
                percent: 50
            });
        }

        if (percent >= 75 && !scroll75) {
            scroll75 = true;

            window.lottusTrack('scroll_depth', {
                percent: 75
            });
        }

        if (percent >= 90 && !scroll90) {
            scroll90 = true;

            window.lottusTrack('scroll_depth', {
                percent: 90
            });
        }
    });
})();

/*
|--------------------------------------------------------------------------
| TEMPO DE PERMANÊNCIA
|--------------------------------------------------------------------------
*/

(function () {
    let tracked30 = false;
    let tracked60 = false;
    let tracked120 = false;

    setInterval(() => {
        const seconds = Math.floor(performance.now() / 1000);

        if (seconds >= 30 && !tracked30) {
            tracked30 = true;

            window.lottusTrack('engagement_time', {
                seconds: 30
            });
        }

        if (seconds >= 60 && !tracked60) {
            tracked60 = true;

            window.lottusTrack('engagement_time', {
                seconds: 60
            });
        }

        if (seconds >= 120 && !tracked120) {
            tracked120 = true;

            window.lottusTrack('engagement_time', {
                seconds: 120
            });
        }
    }, 5000);
})();

/*
|--------------------------------------------------------------------------
| CLICK TRACKING
|--------------------------------------------------------------------------
*/

document.addEventListener('click', function (e) {

    const button = e.target.closest('button, a');

    if (!button) return;

    const text = button.innerText?.trim() || '';
    const id = button.id || '';
    const href = button.href || '';

    window.lottusTrack('click', {
        text,
        id,
        href
    });
});

/*
|--------------------------------------------------------------------------
| FORM TRACKING
|--------------------------------------------------------------------------
*/

document.addEventListener('DOMContentLoaded', () => {

    const formGerar = document.getElementById('form-gerar-jogo');

    if (!formGerar) return;

    const emailInput = document.getElementById('email');
    const quantidadeInput = document.getElementById('quantidade');

    let emailStarted = false;

    if (emailInput) {
        emailInput.addEventListener('focus', () => {
            if (!emailStarted) {
                emailStarted = true;

                window.lottusTrack('lead_start', {
                    source: 'email_field'
                });
            }
        });
    }

    formGerar.addEventListener('submit', () => {

        window.lottusTrack('begin_checkout', {
            email_filled: !!emailInput?.value,
            quantidade: quantidadeInput?.value || null
        });

    });
});

/*
|--------------------------------------------------------------------------
| CUPOM TRACKING
|--------------------------------------------------------------------------
*/

document.addEventListener('DOMContentLoaded', () => {

    const cupomBtn = document.getElementById('btn-validar-cupom');

    if (!cupomBtn) return;

    cupomBtn.addEventListener('click', () => {

        const cupomInput = document.getElementById('cupom');

        window.lottusTrack('coupon_attempt', {
            coupon: cupomInput?.value || null
        });

    });
});

/*
|--------------------------------------------------------------------------
| PEDIDO PAGE TRACKING
|--------------------------------------------------------------------------
*/

document.addEventListener('DOMContentLoaded', () => {

    const currentPath = window.location.pathname;

    if (currentPath.includes('/pedido/')) {

        window.lottusTrack('view_checkout', {
            step: 'pedido'
        });

        const pagarBtn = document.querySelector('a[href*="pagar"]');

        if (pagarBtn) {

            pagarBtn.addEventListener('click', () => {

                window.lottusTrack('payment_click', {
                    gateway: 'mercado_pago'
                });

            });
        }
    }
});

/*
|--------------------------------------------------------------------------
| PURCHASE TRACKING
|--------------------------------------------------------------------------
*/

document.addEventListener('DOMContentLoaded', () => {

    const pageText = document.body.innerText.toLowerCase();

    const paidSignals = [
        'pagamento confirmado',
        'acesso liberado via cupom',
        'pagamento aprovado'
    ];

    const purchased = paidSignals.some(signal =>
        pageText.includes(signal)
    );

    if (purchased) {

        window.lottusTrack('purchase', {
            source: 'pedido_page'
        });

    }
});

/*
|--------------------------------------------------------------------------
| ABANDONO DE PÁGINA
|--------------------------------------------------------------------------
*/

window.addEventListener('beforeunload', () => {

    window.lottusTrack('page_exit', {
        url: window.location.pathname
    });

});