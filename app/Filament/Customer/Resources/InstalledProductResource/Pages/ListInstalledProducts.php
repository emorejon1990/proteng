<?php

namespace App\Filament\Customer\Resources\InstalledProductResource\Pages;

use Filament\Resources\Pages\ListRecords;
use App\Filament\Customer\Resources\InstalledProductResource;

class ListInstalledProducts extends ListRecords
{
    protected static string $resource = InstalledProductResource::class;

    protected function getHeaderActions(): array
    {
        return []; // ✅ no create
    }
}

