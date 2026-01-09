<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use QuickBooksOnline\API\Facades\Customer as QBCustomer;
use App\Services\QuickBooksService;

class CustomerSyncService
{
    public function __construct(
        protected QuickBooksService $qb
    ) {}

    /* ----------------- CREATE ----------------- */

    public function create(array $data): Customer
    {
        $ds = $this->qb->ds();

        $qbCustomer = $ds->Add(QBCustomer::create([
            'DisplayName' => $data['name'],
            'PrimaryEmailAddr' => ['Address' => $data['email']],
            'PrimaryPhone' => ['FreeFormNumber' => $data['phone'] ?? null],
        ]));

        $customer = Customer::updateOrCreate(
            ['quickbooks_id' => $qbCustomer->Id],
            [
                'display_name'  => $qbCustomer->DisplayName ?? $qbCustomer->GivenName ?? 'Cliente',
                'email' => $qbCustomer->PrimaryEmailAddr->Address ?? null,
                'phone' => $qbCustomer->PrimaryPhone->FreeFormNumber ?? null,
            ]
        );

        $this->createUser($customer);

        return $customer;
    }

    /* ----------------- SYNC ----------------- */

    public function sync(): void
    {
        $ds = $this->qb->ds();
        $customers = $ds->Query("SELECT * FROM Customer");

        foreach ($customers as $qbCustomer) {
            $customer = Customer::updateOrCreate(
                ['quickbooks_id' => $qbCustomer->Id],
                [
                    'display_name'  => $qbCustomer->DisplayName,
                    'email' => $qbCustomer->PrimaryEmailAddr->Address ?? null,
                    'phone' => $qbCustomer->PrimaryPhone->FreeFormNumber ?? null,
                ]
            );

            $this->createUser($customer);
        }
    }

    /* ----------------- USERS ----------------- */

    protected function createUser(Customer $customer): void
    {
        if (! $customer->email) return;

        $password = Str::random(12);

        $user = User::firstOrCreate(
            ['email' => $customer->email],
            [
                'name'                 => $customer->display_name,
                'password'             => Hash::make('Abc12345678*'),
                'must_change_password' => true,
            ]
        );

        if (! $user->hasRole('Customer')) {
            $user->assignRole('Customer');
        }
    }
}
