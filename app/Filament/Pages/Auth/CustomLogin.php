<?php

namespace App\Filament\Pages\Auth;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Filament\Pages\Auth\Login as BaseLogin;

class CustomLogin extends BaseLogin
{
    protected static string $view = 'filament.pages.auth.custom-login';

    public function getHeading(): string
    {
        return ''; // 👈 elimina el "Sign in"
    }

    public function getSubHeading(): ?string
    {
        return null; // 👈 elimina subtítulos si los hubiera
    }

    public function hasLogo(): bool
    {
        return false; // 👈 oculta el brandLogo por defecto
    }

    protected function getRedirectUrl(): string
    {
        $user = Auth::user();

        if ($user->hasRole('Admin')) {
            return route('filament.admin.pages.dashboard');
        }

        if ($user->hasRole('Manager')) {
            return route('filament.manager.pages.dashboard');
        }

        if ($user->hasRole('Worker')) {
            return route('filament.worker.pages.dashboard');
        }

        if ($user->hasRole('Customer')) {
            return route('filament.customer.pages.dashboard');
        }

        abort(403);
    }
}
