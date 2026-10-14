<?php

namespace App\Http\Middleware;

use App\Filament\Pages\QuickBooks;
use App\Services\QuickBooksDiagnostics;
use App\Services\QuickBooksService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureQuickBooksTokenIsValid
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $ds = app(QuickBooksService::class)->ds();
        } catch (\Exception $e) {
            QuickBooksDiagnostics::failure('middleware', $e);

            return redirect(QuickBooks::getUrl(panel: $request->user()->hasRole('Admin') ? 'admin' : 'manager'))
                ->with('error', 'No se pudo acceder a QuickBooks. Inténtalo de nuevo o contacta al administrador.');
        }
        $request->attributes->set('quickbooks', $ds);

        return $next($request);
    }
}
