<?php

namespace App\Services;

use App\Models\QuickbooksToken;
use QuickBooksOnline\API\DataService\DataService;

class QuickBooksService
{
    public function ds(?string $expectedRealm = null): DataService
    {
        $token = QuickbooksToken::whereNotNull('realm_id')->latest()->first();

        if (! $token) {
            throw new \Exception('No QuickBooks token found');
        }
        if ($expectedRealm !== null && (string) $token->realm_id !== $expectedRealm) {
            throw new \RuntimeException('QuickBooks company changed during the request. Retry with the original company.');
        }

        $ds = DataService::Configure([
            'auth_mode' => 'oauth2',
            'ClientID' => config('services.quickbooks.client_id'),
            'ClientSecret' => config('services.quickbooks.client_secret'),
            'accessTokenKey' => $token->access_token,
            'refreshTokenKey' => $token->refresh_token,
            'realmId' => (string) $token->realm_id,
            'scope' => config('services.quickbooks.scope'),
            'baseUrl' => config('services.quickbooks.env'),
        ]);

        $ds->throwExceptionOnError(true);
        $ds->getServiceContext()->realmId = (string) $token->realm_id;

        return $ds;
    }
}
