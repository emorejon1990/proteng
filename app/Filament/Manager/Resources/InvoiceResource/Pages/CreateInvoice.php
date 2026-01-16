<?php

namespace App\Filament\Manager\Resources\InvoiceResource\Pages;

use Filament\Actions;
use App\Models\Invoice;
use App\Models\Customer;
use Illuminate\Support\Arr;
use Filament\Resources\Pages\CreateRecord;
use App\Services\InvoiceSyncService;
use App\Filament\Manager\Resources\InvoiceResource;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    public function mount(): void
    {
        parent::mount();

        $customerId = request()->query('customer_id');
        $items = request()->query('items');

        if (is_string($items)) {
            $items = json_decode($items, true) ?? [];
        }

        if (! is_array($items)) {
            $items = [];
        }

        $defaults = [];

        if ($customerId) {
            $defaults['customer_id'] = $customerId;
        }

        if (! empty($items)) {
            $defaults['metadata'] = ['items' => $items];
        }

        if (! empty($defaults)) {
            $this->form->fill($defaults);
        }
    }

    protected function handleRecordCreation(array $data): Invoice
    {
        $customer = Customer::findOrFail($data['customer_id']);
        $items = $data['metadata']['items'] ?? [];

        return app(InvoiceSyncService::class)
            ->create($customer, $items);
    }
}
