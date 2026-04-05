<?php

use App\Http\Controllers\LottusController;
use App\Http\Controllers\MercadoPagoCheckoutController;
use App\Http\Controllers\MercadoPagoWebhookController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicLottusController;
use Illuminate\Support\Facades\Route;

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
Route::get('/pedido/{token}/status', [PublicLottusController::class, 'statusPedido'])->name('pedido.status');

Route::get('/pedido/{token}/pagar', [MercadoPagoCheckoutController::class, 'pagar'])
    ->name('pedido.pagar');

Route::post('/mercado-pago/webhook', [MercadoPagoWebhookController::class, 'handle'])
    ->name('mercado-pago.webhook');

require __DIR__.'/auth.php';