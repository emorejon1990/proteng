<?php

namespace App\Services;

use App\Mail\CustomerAccountCreated;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use QuickBooksOnline\API\Facades\Customer as QBCustomer;

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
                'display_name' => $qbCustomer->DisplayName ?? $qbCustomer->GivenName ?? 'Cliente',
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
        app(QuickBooksSyncLock::class)->run(fn () => $this->syncLocked());
    }

    private function syncLocked(): void
    {
        $gateway = app(QuickBooksReadGateway::class);
        $customers = [];
        $startPosition = 1;
        $pageSize = 1000;

        do {
            $page = $gateway->page('Customer', $startPosition, $pageSize);

            foreach ($page as $customer) {
                $customers[] = $customer;
            }

            $startPosition += $pageSize;
        } while (count($page) === $pageSize);

        foreach ($customers as $qbCustomer) {
            $customer = $this->import($qbCustomer);

            // $this->createUser($customer);
        }
    }

    public function import(array $data): Customer
    {
        app(QuickBooksSyncLock::class)->assertOwned();
        if (empty($data['Id']) || empty($data['DisplayName'])) {
            throw new \RuntimeException('QuickBooks customer response is incomplete.');
        }
        $fields = ['display_name' => $data['DisplayName']];
        foreach (['BillAddr' => 'billing_address', 'ShipAddr' => 'shipping_address'] as $source => $target) {
            if (array_key_exists($source, $data)) {
                $fields[$target] = $data[$source];
            }
        }
        if (array_key_exists('PrimaryEmailAddr', $data)) {
            $fields['email'] = $data['PrimaryEmailAddr']['Address'] ?? null;
        }
        if (array_key_exists('PrimaryPhone', $data)) {
            $fields['phone'] = $data['PrimaryPhone']['FreeFormNumber'] ?? null;
        }
        if (array_key_exists('Active', $data)) {
            $fields['active'] = filter_var($data['Active'], FILTER_VALIDATE_BOOLEAN);
        }

        return Customer::updateOrCreate(['quickbooks_id' => (string) $data['Id']], $fields);
    }

    /* ----------------- USERS ----------------- */

    protected function createUser(Customer $customer): void
    {
        if (! $customer->email) {
            return;
        }

        $password = 'Abc12345678*';

        $user = User::firstOrCreate(
            ['email' => $customer->email],
            [
                'name' => $customer->display_name,
                'password' => Hash::make($password),
                'must_change_password' => true,
            ]
        );

        if ($user->wasRecentlyCreated) {
            Mail::to($customer->email)->send(new CustomerAccountCreated($user, $password));
        }

        if (! $user->hasRole('Customer')) {
            $user->assignRole('Customer');
        }

        if ($customer->user_id !== $user->id) {
            $customer->user_id = $user->id;
            $customer->save();
        }
    }
}
