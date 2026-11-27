<?php

namespace App\Filament\Shared\Resources\EquipmentModelResource\Pages;

use App\Filament\Shared\Resources\EquipmentModelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageEquipmentModels extends ManageRecords
{
    protected static string $resource = EquipmentModelResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->databaseTransaction()];
    }
}
