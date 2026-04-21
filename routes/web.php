<?php

use App\Http\Controllers\Admin\CupomController as AdminCupomController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\PedidoController as AdminPedidoController;
use App\Http\Controllers\LottusController;
use App\Http\Controllers\MercadoPagoCheckoutController;
use App\Http\Controllers\MercadoPagoWebhookController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicCupomController;
use App\Http\Controllers\PublicLottusController;
use App\Http\Controllers\Lottus\NovoGeracaoJogosController;
use App\Http\Middleware\AdminMiddleware;
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

Route::middleware(['auth', AdminMiddleware::class])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

    Route::get('/pedidos', [AdminPedidoController::class, 'index'])->name('pedidos.index');

    Route::get('/cupons', [AdminCupomController::class, 'index'])->name('cupons.index');
    Route::get('/cupons/criar', [AdminCupomController::class, 'create'])->name('cupons.create');
    Route::post('/cupons', [AdminCupomController::class, 'store'])->name('cupons.store');
    Route::get('/cupons/{cupom}/editar', [AdminCupomController::class, 'edit'])->name('cupons.edit');
    Route::put('/cupons/{cupom}', [AdminCupomController::class, 'update'])->name('cupons.update');
    Route::delete('/cupons/{cupom}', [AdminCupomController::class, 'destroy'])->name('cupons.destroy');
});

Route::get('/', [PublicLottusController::class, 'home'])->name('home');
// Route::post('/gerar-jogo', [PublicLottusController::class, 'gerarJogo'])->name('jogos.gerar');
Route::post('/cupom/validar', [PublicCupomController::class, 'validar'])->name('cupom.validar');
Route::post('/jogos/gerar', [NovoGeracaoJogosController::class, 'gerar'])->name('jogos.gerar');

Route::get('/pedido/{token}', [PublicLottusController::class, 'showPedido'])->name('pedido.show');
Route::get('/pedido/{token}/status', [PublicLottusController::class, 'statusPedido'])->name('pedido.status');

Route::get('/pedido/{token}/pagar', [MercadoPagoCheckoutController::class, 'pagar'])
    ->name('pedido.pagar');

Route::post('/mercado-pago/webhook', [MercadoPagoWebhookController::class, 'handle'])
    ->name('mercado-pago.webhook');

require __DIR__.'/auth.php';