<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('livewire.update')) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $panelId = Filament::getCurrentPanel()?->getId();

        if (! $panelId) {
            return $next($request);
        }

        $panelRoles = [
            'admin'    => ['Admin'],
            'manager'  => ['Admin', 'Manager'],
            'worker'   => ['Admin', 'Manager', 'Worker'],
            'customer' => ['Customer', 'Inst_Manager'],
            'auth'     => ['Admin', 'Manager', 'Inst_Manager', 'Worker', 'Customer'],
        ];

        if (! array_key_exists($panelId, $panelRoles)) {
            abort(403, 'Panel not allowed.');
        }

        if (! $user->hasRol($panelRoles[$panelId])) {
            abort(403, 'Access Denied to this panel.');
        }

        return $next($request);
    }
}
