<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChange
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle($request, Closure $next)
    {
        if ($request->is('livewire/*') || $request->routeIs('livewire.update') || $request->hasHeader('X-Livewire')) {
            return $next($request);
        }

        $user = Auth::user();

        // Si no hay usuario autenticado, seguimos sin verificar
        if (! $user) {
            return $next($request);
        }

        // Si el usuario debe cambiar la contraseña y no está en la página de cambio
        if (
            $user->hasRole('Customer') &&
            $user->must_change_password &&
            ! $request->is('filament.*.pages.force-change-password')
            ) {
            return redirect()->route('filament.customer.pages.force-password-change');
        }

        return $next($request);
    }
}
