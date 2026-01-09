<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Facades\Customer as QBCustomer;
use QuickBooksOnline\API\Facades\Invoice as QBInvoice;

class QuickBooksService
{
    public function ds(): DataService
    {
        $token = \App\Models\QuickbooksToken::whereNotNull('realm_id')->latest()->first();

        if (! $token) {
            throw new \Exception('No QuickBooks token found');
        }

        $ds = DataService::Configure([
            'auth_mode'        => 'oauth2',
            'ClientID'         => config('services.quickbooks.client_id'),
            'ClientSecret'     => config('services.quickbooks.client_secret'),
            'accessTokenKey'   => $token->access_token,
            'refreshTokenKey'  => $token->refresh_token,
            'realmId'          => (string) $token->realm_id,
            'scope'            => config('services.quickbooks.scope'),
            'baseUrl'          => config('services.quickbooks.env'),
        ]);

        $ds->throwExceptionOnError(true);
        $ds->getServiceContext()->realmId = (string) $token->realm_id;

        return $ds;
    }
}
