<?php

namespace App\Filament\Resources\WareHouseResource\Pages;

use App\Filament\Resources\WareHouseResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWareHouses extends ListRecords
{
    protected static string $resource = WareHouseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
