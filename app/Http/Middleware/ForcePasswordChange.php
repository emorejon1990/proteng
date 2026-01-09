<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        // ✅ 1) No tocar endpoints internos de Livewire
        if ($request->is('livewire/*') || $request->routeIs('livewire.update') || $request->hasHeader('X-Livewire')) {
            return $next($request);
        }

        // ✅ 2) No bloquear login / logout (importante para evitar loops)
        if ($request->routeIs('filament.auth.auth.login') || $request->routeIs('login')) {
            return $next($request);
        }

        if ($request->routeIs('filament.auth.auth.logout') || $request->routeIs('logout')) {
            return $next($request);
        }

        $user = Auth::user();

        // Si no hay usuario autenticado, no hacemos nada
        if (! $user) {
            return $next($request);
        }

        // ✅ 3) Solo aplica a Customers
        if (! $user->hasRole('Customer')) {
            return $next($request);
        }

        // ✅ 4) Si ya está en la página de cambio, dejarlo pasar
        if ($request->routeIs('filament.customer.pages.force-password-change')) {
            return $next($request);
        }

        // ✅ 5) Si debe cambiar, redirige
        if ((bool) ($user->must_change_password ?? false) === true) {
            return redirect()->route('filament.customer.pages.force-password-change');
        }

        return $next($request);
    }
}
