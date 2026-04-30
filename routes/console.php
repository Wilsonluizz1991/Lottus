<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(\Illuminate\Foundation\Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('lottus:sincronizar-lotofacil')
    ->days([1, 2, 3, 4, 5, 6]) // segunda a sábado
    ->at('22:00')
    ->timezone('America/Sao_Paulo');