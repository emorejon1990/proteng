<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Filament\Facades\Filament;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next): Response
    {
        // ✅ IMPORTANTE: Livewire actualiza vía esta ruta global
        if ($request->routeIs('livewire.update')) {
            return $next($request);
        }

        $user = $request->user();

        // Si no hay usuario, que lo maneje Authenticate de Filament
        if (! $user) {
            return $next($request);
        }

        // Ojo: puede ser null fuera de rutas del panel
        $panelId = Filament::getCurrentPanel()?->getId();

        // Si no estamos dentro de un panel Filament, no bloquees aquí
        if (! $panelId) {
            return $next($request);
        }

        // ✅ Mapa panel → roles permitidos (usa tus nombres EXACTOS)
        $panelRoles = [
            'admin'    => ['Admin'],
            'manager'  => ['Admin', 'Manager'],
            'worker'   => ['Admin', 'Manager', 'Worker'],
            'customer' => ['Customer'], // 👈 SOLO customer
            'auth'     => ['Admin', 'Manager', 'Worker', 'Customer'], // si tienes AuthPanel
        ];

        // Si el panel no está en el mapa, por seguridad deniega:
        if (! array_key_exists($panelId, $panelRoles)) {
            abort(403, 'Panel not allowed.');
        }

        // ✅ Spatie: acepta string|array
        if (! $user->hasRole($panelRoles[$panelId])) {
            abort(403, 'Access Denied to this panel.');
        }

        return $next($request);
    }
}
