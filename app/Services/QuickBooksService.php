<?php

namespace App\Services;

use App\Models\QuickbooksToken;
use Illuminate\Support\Facades\Cache;
use QuickBooksOnline\API\DataService\DataService;

class QuickBooksService
{
    public function ds(?string $expectedRealm = null): DataService
    {
        $token = QuickbooksToken::whereNotNull('realm_id')->latest()->first();
        if (! $token) {
            throw new \RuntimeException('No QuickBooks token found');
        }
        if ($expectedRealm !== null && (string) $token->realm_id !== $expectedRealm) {
            throw new \RuntimeException('QuickBooks company changed during the request. Retry with the original company.');
        }

        try {
            // Serialize rotating refresh tokens; reread after acquiring the lock.
            return Cache::lock('quickbooks.refresh.'.$token->realm_id, 120)->block(15, function () use ($token) {
                $token->refresh();
                $ds = app(QuickBooksDataServiceFactory::class)->make([
                    'accessTokenKey' => $token->access_token,
                    'refreshTokenKey' => $token->refresh_token,
                    'realmId' => (string) $token->realm_id,
                ]);
                $key = 'quickbooks.valid.'.hash('sha256', $token->access_token);
                if (! Cache::has($key)) {
                    $new = $ds->getOAuth2LoginHelper()->refreshAccessTokenWithRefreshToken($token->refresh_token);
                    if (! $new || ! $new->getAccessToken() || ! $new->getRefreshToken()) {
                        throw new \RuntimeException('QuickBooks returned incomplete tokens.');
                    }
                    $token->update(['access_token' => $new->getAccessToken(), 'refresh_token' => $new->getRefreshToken()]);
                    $ds->updateOAuth2Token($new);
                    Cache::put('quickbooks.valid.'.hash('sha256', $new->getAccessToken()), true, now()->addMinutes(50));
                }
                $ds->getServiceContext()->realmId = (string) $token->realm_id;

                return $ds;
            });
        } catch (\Exception $e) {
            QuickBooksDiagnostics::failure('saved-token', $e);
            // Downstream consumers may display/log the exception message.
            throw new \RuntimeException('No se pudo acceder a QuickBooks. Revisa la configuración y la conexión.');
        }
    }
}
