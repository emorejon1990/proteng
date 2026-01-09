<?php

namespace App\Filament\Customer\Resources\InvoiceResource\Pages;

use Filament\Resources\Pages\ListRecords;
use App\Filament\Customer\Resources\InvoiceResource;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

