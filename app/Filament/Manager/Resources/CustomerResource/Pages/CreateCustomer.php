<?php

namespace App\Filament\Manager\Resources\CustomerResource\Pages;

use Filament\Actions;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Filament\Resources\Pages\CreateRecord;
use App\Services\Customers\CustomerSyncService;
use App\Filament\Manager\Resources\CustomerResource;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    protected function handleRecordCreation(array $data): Customer
    {
        return app(CustomerSyncService::class)->create($data);
    }
}
