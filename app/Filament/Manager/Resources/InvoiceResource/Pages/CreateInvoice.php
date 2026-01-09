<?php

namespace App\Filament\Manager\Resources\InvoiceResource\Pages;

use Filament\Actions;
use App\Models\Invoice;
use App\Models\Customer;
use Filament\Resources\Pages\CreateRecord;
use App\Services\Invoices\InvoiceSyncService;
use App\Filament\Manager\Resources\InvoiceResource;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function handleRecordCreation(array $data): Invoice
    {
        $customer = Customer::findOrFail($data['customer_id']);

        return app(InvoiceSyncService::class)
            ->create($customer, $data['items']);
    }
}
