<?php

namespace App\Filament\Shared\Resources\EquipmentBrandResource\Pages;

use App\Filament\Shared\Resources\EquipmentBrandResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageEquipmentBrands extends ManageRecords
{
    protected static string $resource = EquipmentBrandResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->databaseTransaction()];
    }
}
