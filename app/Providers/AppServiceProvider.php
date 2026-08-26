<?php

namespace App\Providers;

use App\Application\Production\ProductionEnvironmentContract;
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
        if ($this->app->environment('production')) {
            app(
                ProductionEnvironmentContract::class
            )->validate();
        }
    }
}
