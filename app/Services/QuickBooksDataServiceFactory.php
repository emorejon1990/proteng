<?php

namespace App\Services;

use QuickBooksOnline\API\DataService\DataService;

class QuickBooksDataServiceFactory
{
    public function make(array $overrides = []): DataService
    {
        $settings = array_merge([
            'auth_mode' => 'oauth2',
            'ClientID' => config('services.quickbooks.client_id'),
            'ClientSecret' => config('services.quickbooks.client_secret'),
            'RedirectURI' => config('services.quickbooks.redirect_uri'),
            'scope' => config('services.quickbooks.scope'),
            'baseUrl' => config('services.quickbooks.env'),
        ], $overrides);

        foreach (['ClientID', 'ClientSecret', 'baseUrl'] as $key) {
            $this->requireValue($settings, $key);
        }
        $settings['baseUrl'] = match (strtolower(trim($settings['baseUrl']))) {
            'sandbox', 'development' => 'Development',
            'production' => 'Production',
            default => throw new \RuntimeException('QuickBooks configuration: invalid environment.'),
        };
        if (! isset($settings['accessTokenKey'])) {
            foreach (['RedirectURI', 'scope'] as $key) {
                $this->requireValue($settings, $key);
            }
            if (! filter_var($settings['RedirectURI'], FILTER_VALIDATE_URL)
                || ! in_array(parse_url($settings['RedirectURI'], PHP_URL_SCHEME), ['http', 'https'], true)) {
                throw new \RuntimeException('QuickBooks configuration: invalid RedirectURI.');
            }
        } else {
            foreach (['accessTokenKey', 'refreshTokenKey', 'realmId'] as $key) {
                $this->requireValue($settings, $key);
            }
        }

        $ds = DataService::Configure($settings);
        $ds->throwExceptionOnError(true);
        $ds->disableLog();
        $ds->setLogForOAuthCalls(false, false, null);

        return $ds;
    }

    private function requireValue(array $settings, string $key): void
    {
        if (! is_string($settings[$key] ?? null) || trim($settings[$key]) === '') {
            throw new \RuntimeException('QuickBooks configuration: missing '.$key.'.');
        }
    }
}
