<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\LottusController;
use App\Http\Controllers\MercadoPagoWebhookController;
use App\Http\Controllers\PublicLottusController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/lottus', [LottusController::class, 'index'])->name('lottus.index');
    Route::post('/lottus/gerar-aposta', [LottusController::class, 'gerarAposta'])->name('lottus.gerar-aposta');
    Route::post('/lottus/salvar-resultado-e-gerar', [LottusController::class, 'salvarResultadoEGerar'])->name('lottus.salvar-resultado-e-gerar');

});

Route::get('/', [PublicLottusController::class, 'home'])->name('home');
Route::post('/gerar-jogo', [PublicLottusController::class, 'gerarJogo'])->name('jogos.gerar');
Route::get('/pedido/{token}', [PublicLottusController::class, 'showPedido'])->name('pedido.show');

Route::post('/pagamentos/mercadopago/webhook', [MercadoPagoWebhookController::class, 'handle'])
    ->name('pagamentos.mercadopago.webhook');

require __DIR__.'/auth.php';

Route::prefix('pagamentos/mercadopago')->group(function () {

    Route::get('/success', function (\Illuminate\Http\Request $request) {
        return view('payments.return', [
            'type' => 'success',
            'query' => $request->query()
        ]);
    })->name('pagamentos.mercadopago.success');

    Route::get('/failure', function (\Illuminate\Http\Request $request) {
        return view('payments.return', [
            'type' => 'failure',
            'query' => $request->query()
        ]);
    })->name('pagamentos.mercadopago.failure');

    Route::get('/pending', function (\Illuminate\Http\Request $request) {
        return view('payments.return', [
            'type' => 'pending',
            'query' => $request->query()
        ]);
    })->name('pagamentos.mercadopago.pending');

    Route::get('/pagamento/{token}', function ($token) {
    $pedido = \App\Models\LottusPedido::where('token', $token)->firstOrFail();

    $checkout = app(\App\Services\MercadoPagoCheckoutService::class)
        ->criarCheckout($pedido);

    return redirect()->away($checkout['init_point']);
})->name('pagamento.checkout');

});
