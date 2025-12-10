<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use QuickBooksOnline\API\DataService\DataService;
use App\Models\QuickbooksToken;
use Exception;

class QuickBooksController extends Controller
{
    protected function makeDataService(array $overrides = [])
    {
        $config = array_merge([
            'auth_mode' => 'oauth2',
            'ClientID' => env('QB_CLIENT_ID'),
            'ClientSecret' => env('QB_CLIENT_SECRET'),
            'RedirectURI' => env('QB_REDIRECT_URI'),
            'scope' => env('QB_SCOPE'),
            'baseUrl' => env('QB_ENV'), // 'sandbox' o 'production'
        ], $overrides);

        // dd($config);

        return DataService::Configure($config);
    }

    public function connect()
    {
        $dataService = $this->makeDataService();

        // @var \QuickBooksOnline\API\Core\OAuth\OAuth2\OAuth2LoginHelper $oauth2LoginHelper
        $oauth2LoginHelper = $dataService->getOAuth2LoginHelper();

        // genera la URL de autorización
        $authUrl = $oauth2LoginHelper->getAuthorizationCodeURL();

        return redirect($authUrl);
    }

    public function callback(Request $request)
    {
        try {
            $dataService = $this->makeDataService();

            // @var \QuickBooksOnline\API\Core\OAuth\OAuth2\OAuth2LoginHelper $oauth2LoginHelper
            $oauth2LoginHelper = $dataService->getOAuth2LoginHelper();

            // intercambia el code por tokens
            $accessTokenObj = $oauth2LoginHelper->exchangeAuthorizationCodeForToken(
                $request->input('code'),
                $request->input('realmId')
            );

            // El objeto devuelto suele ser OAuth2AccessToken
            if (! $accessTokenObj) {
                throw new Exception('No se obtuvo token de QuickBooks');
            }

            // guarda tokens (encripta a través del modelo)
            QuickbooksToken::updateOrCreate(
                ['realm_id' => $request->input('realmId')],
                [
                    'access_token' => $accessTokenObj->getAccessToken(),
                    'refresh_token' => $accessTokenObj->getRefreshToken(),
                ]
            );

            return redirect('/quick-books')->with('success', 'QuickBooks conectado correctamente');
        } catch (Exception $e) {
            // log para depuración
            \Log::error('QuickBooks callback error: '.$e->getMessage(), [
                'request' => $request->all(),
            ]);

            return redirect('/quick-books')->with('error', 'Error al conectar con QuickBooks: ' . $e->getMessage());
        }
    }

    /**
     * Crea un DataService listo para usar con tokens guardados (y actualiza tokens si se renuevan).
     *
     * @param  QuickbooksToken  $tokenModel
     * @return DataService
     */
    protected function dataServiceFromSavedToken(QuickbooksToken $tokenModel)
    {
        $dataService = $this->makeDataService([
            'accessTokenKey' => $tokenModel->access_token,
            'refreshTokenKey' => $tokenModel->refresh_token,
            'realmId' => $tokenModel->realm_id,
        ]);

        // Si el SDK requiere un objeto de token
        // opcional: you can call $dataService->updateOAuth2Token($tokenObject);
        return $dataService;
    }

    /**
     * Ejemplo simple: obtener clientes
     */
    public function customers(Request $request)
    {
        /** @var \QuickBooksOnline\API\DataService\DataService $dataService */
        $dataService = $request->attributes->get('quickbooks');

        $customers = $dataService->Query("SELECT * FROM Customer MAXRESULTS 20");

        return $customers;
    }
}


