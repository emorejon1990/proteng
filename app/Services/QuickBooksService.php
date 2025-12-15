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
    protected function ds(): DataService
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

    /* ------------------ CUSTOMERS ------------------ */

    public function createCustomer(array $data): Customer
    {
        $ds = $this->ds();

        $qbCustomer = $ds->Add(QBCustomer::create([
            'DisplayName' => $data['name'],
            'PrimaryEmailAddr' => ['Address' => $data['email']],
            'PrimaryPhone' => ['FreeFormNumber' => $data['phone'] ?? null],
        ]));

        $customer = Customer::create([
            'quickbooks_id' => $qbCustomer->Id,
            'name'          => $qbCustomer->DisplayName ?? $qbCustomer->GivenName ?? 'Cliente',
            'email'         => $qbCustomer->PrimaryEmailAddr->Address ?? null,
            'phone'         => $qbCustomer->PrimaryPhone->FreeFormNumber ?? null,
        ]);

        $this->createUserForCustomer($customer);

        return $customer;
    }

    protected function createUserForCustomer(Customer $customer): void
    {
        if (! $customer->email) {
            return;
        }

        $user = User::firstOrCreate(
            ['email' => $customer->email],
            [
                'name'                 => $customer->name,
                'password'             => Hash::make('Abc12345678*'),
                'must_change_password' => true,
            ]
        );

        if (! $user->hasRole('Customer')) {
            $user->assignRole('Customer');
        }
    }

    /* ------------------ INVOICES ------------------ */

    public function createInvoice(Customer $customer, array $items): Invoice
    {
        $ds = $this->ds();

        $qbInvoice = $ds->Add(QBInvoice::create([
            'CustomerRef' => ['value' => $customer->quickbooks_id],
            'Line' => collect($items)->map(fn ($item) => [
                'Amount' => $item['qty'] * $item['price'],
                'DetailType' => 'SalesItemLineDetail',
                'SalesItemLineDetail' => [
                    'Qty'       => $item['qty'],
                    'UnitPrice'=> $item['price'],
                ],
            ])->toArray(),
        ]));

        return Invoice::create([
            'quickbooks_id' => $qbInvoice->Id,
            'customer_id'   => $customer->id,
            'total'         => $qbInvoice->TotalAmt ?? $qbInvoice->totalAmt ?? 0,
            'status'        => (($qbInvoice->Balance ?? $qbInvoice->balance ?? 0) > 0) ? 'open' : 'paid',
        ]);
    }
}
