<?php

namespace App\Providers\Filament;

use Filament\Pages;
use Filament\Panel;
use App\Models\User;
use Filament\Widgets;
use Filament\PanelProvider;
use Filament\Enums\ThemeMode;
use App\Http\Middleware\CheckRole;
use Filament\Support\Colors\Color;
use Filament\Navigation\NavigationItem;
use App\Filament\Pages\Auth\CustomLogin;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Filament\Http\Middleware\AuthenticateSession;
use App\Filament\Shared\Resources\InventoryResource;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;

class CustomerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('customer')
            ->path('customer')
            ->brandName('PROTENG LAB - Customer')
            ->brandLogo(asset('/proteng.png'))
            ->favicon(asset('/proteng.png'))
            // ->login(CustomLogin::class)
            // ->authorize(fn ($user) => $user->hasRole === 'Customer') // <- Aquí va la autorización
            ->colors([
                'primary' => Color::Pink,
            ])
            ->discoverResources(in: app_path('Filament/Customer/Resources'), for: 'App\\Filament\\Customer\\Resources')
            ->discoverResources(in: app_path('Filament/Shared/Resources'), for: 'App\\Filament\\Shared\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverPages(in: app_path('Filament/Customer/Pages'), for: 'App\\Filament\\Customer\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Customer/Widgets'), for: 'App\\Filament\\Customer\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->middleware([
                \App\Http\Middleware\ForcePasswordChange::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                CheckRole::class,
            ])
            ->navigationItems([
                NavigationItem::make('My Invoices')
                    ->url(fn () => InventoryResource::getUrl('dash'))
                    ->icon('heroicon-o-rectangle-group')
                    ->sort('1')
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->defaultThemeMode(ThemeMode::Light)
            ->darkMode(false);
    }
}
