<?php

namespace App\Providers;

use App\Http\Responses\Auth\LoginResponse;
use App\Http\Responses\Auth\LogoutResponse;
use App\Models\EquipmentBrand;
use App\Models\EquipmentModel;
use App\Models\Installation;
use App\Models\InstallationStep;
use App\Policies\InstallationPolicy;
use App\Policies\EquipmentCatalogPolicy;
use App\Policies\InstallationStepPolicy;
use App\Services\QuickBooksSyncLock;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(QuickBooksSyncLock::class);
        $this->app->bind(
            \Filament\Http\Responses\Auth\Contracts\LoginResponse::class,
            LoginResponse::class,
        );
        $this->app->bind(
            \Filament\Http\Responses\Auth\Contracts\LogoutResponse::class,
            LogoutResponse::class,
        );
    }

    public function boot(): void
    {
        Model::unguard();

        Gate::policy(Installation::class, InstallationPolicy::class);
        Gate::policy(InstallationStep::class, InstallationStepPolicy::class);
        Gate::policy(EquipmentBrand::class, EquipmentCatalogPolicy::class);
        Gate::policy(EquipmentModel::class, EquipmentCatalogPolicy::class);

        FilamentAsset::register([
            Js::make('weightscale', __DIR__.'/../../resources/js/weightscale.js')->loadedOnRequest(),
        ]);
    }
}
