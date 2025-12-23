<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\User;
use Illuminate\Http\Request;
use Filament\Facades\Filament;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        $panelId = Filament::getCurrentPanel()?->getId();

        // Mapa panel → roles permitidos
        $panelRoles = [
            'admin'   => ['Admin'],
            'manager' => ['Manager'],
            'worker'  => ['Worker'],
            'customer'=> ['Customer'],
        ];

        if (! $user->hasRole($panelRoles[$panelId])) {
            abort(403, 'Access Denied to this panel.');
        }

        return $next($request);
    }
}
