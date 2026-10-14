<?php

namespace App\Http\Controllers;

use App\Filament\Pages\QuickBooks;
use App\Models\QuickbooksToken;
use App\Services\QuickBooksDataServiceFactory;
use App\Services\QuickBooksDiagnostics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use QuickBooksOnline\API\DataService\DataService;

class QuickBooksController extends Controller
{
    protected function makeDataService(array $overrides = []): DataService
    {
        return app(QuickBooksDataServiceFactory::class)->make($overrides);
    }

    private function returnUrl(Request $request): string
    {
        return QuickBooks::getUrl(panel: $request->user()?->hasRole('Admin') ? 'admin' : 'manager');
    }

    public function connect(Request $request)
    {
        try {
            $state = bin2hex(random_bytes(32));
            $ds = $this->makeDataService(['state' => $state]);
            $url = $ds->getOAuth2LoginHelper()->getAuthorizationCodeURL();
            $request->session()->put('quickbooks.oauth', [
                'state' => $state, 'user_id' => $request->user()->getAuthIdentifier(),
                'expires_at' => now()->addMinutes(10)->timestamp,
            ]);

            return redirect()->away($url);
        } catch (\Exception $e) {
            QuickBooksDiagnostics::failure('connect', $e);

            return redirect($this->returnUrl($request))->with('error', 'No se pudo iniciar la conexión con QuickBooks. Contacta al administrador.');
        }
    }

    public function callback(Request $request)
    {
        $pending = $request->session()->get('quickbooks.oauth');
        $state = $request->input('state');
        if (! is_array($pending) || ! is_string($state)
            || ! hash_equals($pending['state'], $state)
            || $pending['expires_at'] < now()->timestamp
            || (string) $pending['user_id'] !== (string) $request->user()->getAuthIdentifier()) {
            return redirect($this->returnUrl($request))->with('error', 'La autorización no es válida o ha vencido. Inicia la conexión de nuevo.');
        }
        $request->session()->forget('quickbooks.oauth');
        if ($request->has('error')) {
            return redirect($this->returnUrl($request))->with('error', 'La autorización de QuickBooks fue cancelada o rechazada.');
        }
        $code = $request->input('code');
        $realm = $request->input('realmId');
        if (! is_string($code) || trim($code) === '' || ! is_string($realm) || ! preg_match('/^\d+$/', $realm)) {
            return redirect($this->returnUrl($request))->with('error', 'QuickBooks devolvió una autorización incompleta.');
        }
        try {
            $token = $this->makeDataService()->getOAuth2LoginHelper()->exchangeAuthorizationCodeForToken($code, $realm);
            if (! $token || ! $token->getAccessToken() || ! $token->getRefreshToken()) {
                throw new \RuntimeException('QuickBooks returned incomplete tokens.');
            }
            Cache::lock('quickbooks.refresh.'.$realm, 120)->block(15, function () use ($realm, $token) {
                QuickbooksToken::updateOrCreate(['realm_id' => $realm], [
                    'access_token' => $token->getAccessToken(), 'refresh_token' => $token->getRefreshToken(),
                ]);
            });

            return redirect($this->returnUrl($request))->with('success', 'QuickBooks conectado correctamente');
        } catch (\Exception $e) {
            QuickBooksDiagnostics::failure('callback', $e);

            return redirect($this->returnUrl($request))->with('error', 'No se pudo completar la conexión con QuickBooks. Inténtalo de nuevo o contacta al administrador.');
        }
    }

    protected function dataServiceFromSavedToken(QuickbooksToken $tokenModel): DataService
    {
        return $this->makeDataService([
            'accessTokenKey' => $tokenModel->access_token,
            'refreshTokenKey' => $tokenModel->refresh_token,
            'realmId' => (string) $tokenModel->realm_id,
        ]);
    }

    public function customers(Request $request)
    {
        try {
            return $request->attributes->get('quickbooks')->Query('SELECT * FROM Customer MAXRESULTS 20');
        } catch (\Exception $e) {
            QuickBooksDiagnostics::failure('customers', $e);
            abort(502, 'No se pudo consultar QuickBooks. Inténtalo de nuevo.');
        }
    }

    public function invoices(Request $request)
    {
        try {
            return $request->attributes->get('quickbooks')->Query('SELECT * FROM Invoice MAXRESULTS 20');
        } catch (\Exception $e) {
            QuickBooksDiagnostics::failure('invoices', $e);
            abort(502, 'No se pudo consultar QuickBooks. Inténtalo de nuevo.');
        }
    }
}
