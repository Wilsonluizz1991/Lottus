<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->preventRequestForgery(except: [
            'mercado-pago/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->withCommands([
        \App\Console\Commands\LottusBacktestFechamentoCommand::class,
        \App\Console\Commands\Lottus\AprenderFechamentoCommand::class,
        \App\Console\Commands\LottusMainLearningReprocessCommand::class,
        \App\Console\Commands\LottusMainLearningReportCommand::class,
        \App\Console\Commands\LottusMainLearningTrainCommand::class,
        \App\Console\Commands\LottusMainLearningStatusCommand::class,
    ])
    ->create();
