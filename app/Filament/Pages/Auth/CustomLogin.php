<?php

namespace App\Filament\Pages\Auth;

use Filament\Facades\Filament;
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

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            session()->forget('url.intended');
            $this->redirect(Filament::auth()->user()->getPanelUrl());

            return;
        }

        parent::mount();
    }
}
