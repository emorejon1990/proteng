<?php

namespace App\Providers;

use App\Models\Installation;
use App\Models\InstallationStep;
use App\Policies\InstallationPolicy;
use App\Policies\InstallationStepPolicy;
use Filament\Support\Assets\Js;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Filament\Support\Facades\FilamentAsset;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Model::unguard();

        Gate::policy(Installation::class, InstallationPolicy::class);
        Gate::policy(InstallationStep::class, InstallationStepPolicy::class);

        FilamentAsset::register([
            Js::make('weightscale', __DIR__ . '/../../resources/js/weightscale.js')->loadedOnRequest(),
        ]);
    }
}
