<?php

namespace App\Filament\Resources\WareHouseResource\Pages;

use App\Filament\Resources\WareHouseResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditWareHouse extends EditRecord
{
    protected static string $resource = WareHouseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
