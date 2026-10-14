<?php

namespace App\Providers\Filament;

use App\Filament\Customer\Resources\InvoiceResource;
use App\Filament\Pages\Auth\CustomLogin;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\ForcePasswordChange;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

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
                ForcePasswordChange::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->navigationItems([
                NavigationItem::make('My Invoices')
                    ->url(fn () => InvoiceResource::getUrl('index'))
                    ->visible(fn () => InvoiceResource::canAccess())
                    ->icon('heroicon-o-rectangle-group')
                    ->sort('1'),
            ])
            ->authMiddleware([
                Authenticate::class,
                CheckRole::class,
            ], isPersistent: true)
            ->defaultThemeMode(ThemeMode::Light)
            ->darkMode(false);
    }
}
