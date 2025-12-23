<?php

namespace App\Filament\Admin\Resources\WareHouseResource\Pages;

use App\Filament\Admin\Resources\WareHouseResource;
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

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
