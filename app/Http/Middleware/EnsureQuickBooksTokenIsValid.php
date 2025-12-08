<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\QuickbooksToken;
use QuickBooksOnline\API\DataService\DataService;
use Symfony\Component\HttpFoundation\Response;
use Exception;

class EnsureQuickBooksTokenIsValid
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tokenModel = QuickbooksToken::first();

        // ✅ 1. Verifica si hay token guardado
        if (! $tokenModel) {
            return redirect('/')
                ->with('error', 'QuickBooks no está conectado. Conéctalo primero.');
        }

        // ✅ 2. Crea el DataService con token actual
        $dataService = DataService::Configure([
            'auth_mode'        => 'oauth2',
            'ClientID'         => env('QB_CLIENT_ID'),
            'ClientSecret'     => env('QB_CLIENT_SECRET'),
            'accessTokenKey'  => $tokenModel->access_token,
            'refreshTokenKey' => $tokenModel->refresh_token,
            'realmId'          => $tokenModel->realm_id,
            'scope'            => env('QB_SCOPE'),
            'baseUrl'          => env('QB_ENV', 'production'),
        ]);

        // ✅ 3. Intento de renovación automática (seguro)
        try {
            $oauthHelper = $dataService->getOAuth2LoginHelper();

            $newToken = $oauthHelper->refreshAccessTokenWithRefreshToken(
                $tokenModel->refresh_token
            );

            if ($newToken) {
                // Guarda tokens nuevos
                $tokenModel->update([
                    'access_token'   => $newToken->getAccessToken(),
                    'refresh_token'  => $newToken->getRefreshToken(),
                ]);

                // Actualiza DataService con el token nuevo
                $dataService->updateOAuth2Token($newToken);
            }

        } catch (Exception $e) {
            // ❌ Si el refresh falla, el token ya no es válido
            \Log::error('QuickBooks token refresh failed: ' . $e->getMessage());

            return redirect('/admin')
                ->with('error', 'Sesión con QuickBooks vencida. Debes reconectar.');
        }

        // ✅ 4. Inyecta el DataService al request (para usarlo en Controllers)
        $request->attributes->set('quickbooks', $dataService);

        return $next($request);
    }
}
