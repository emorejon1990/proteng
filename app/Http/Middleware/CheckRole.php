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
        $panel = Filament::getCurrentPanel();
        abort_unless($panel, 403, 'Panel not allowed.');

        $user = Filament::auth()->user();
        abort_unless($user && $user->canAccessPanel($panel), 403, 'Access denied to this panel.');

        return $next($request);
    }
}
