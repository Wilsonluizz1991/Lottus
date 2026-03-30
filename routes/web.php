<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\LottusController;
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

require __DIR__.'/auth.php';
