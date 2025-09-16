<?php

namespace App\Providers;

use Filament\Support\Assets\Js;
use Illuminate\Support\Facades\URL;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Filament\Support\Facades\FilamentAsset;

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
        Model::unguard();
        FilamentAsset::register([
            Js::make('weightscale', __DIR__ . '/../../resources/js/weightscale.js')->loadedOnRequest(),
        ]);

        // // Forzar HTTPS en entornos adecuados
        // if ($this->app->environment(['staging', 'production', 'demo']) && !$this->app->environment('testing')) {
        //     URL::forceHttps();
        // }

        // // Cabeceras de seguridad en producción
        // if ($this->app->environment('production')) {
        //     $this->app['request']->server->set('HTTPS', true);
        // }
    }
}
