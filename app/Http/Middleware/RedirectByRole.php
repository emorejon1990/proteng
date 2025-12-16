<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectByRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (!$user) {
            return redirect('/login');
        }

        if ($user->hasRole('Admin')) {
            return redirect()->to('/admin');
        } elseif ($user->hasRole('Manager')) {
            return redirect()->to('/manager');
        } elseif ($user->hasRole('Worker')) {
            return redirect()->to('/worker');
        } elseif ($user->hasRole('Customer')) {
            return redirect()->to('/customer');
        }

        return $next($request);
    }
}
