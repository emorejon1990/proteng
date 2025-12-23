<?php

namespace App\Filament\Admin\Resources\WareHouseResource\Pages;

use App\Filament\Admin\Resources\WareHouseResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateWareHouse extends CreateRecord
{
    protected static string $resource = WareHouseResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
