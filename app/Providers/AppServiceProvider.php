<?php

namespace App\Providers;

use App\Events\LotofacilConcursoSincronizado;
use App\Listeners\TriggerAdaptiveLearning;
use App\Listeners\TriggerMainLearning;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(
            LotofacilConcursoSincronizado::class,
            TriggerAdaptiveLearning::class
        );

        Event::listen(
            LotofacilConcursoSincronizado::class,
            TriggerMainLearning::class
        );
    }
}
