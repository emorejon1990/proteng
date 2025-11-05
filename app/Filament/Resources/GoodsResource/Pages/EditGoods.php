<?php

namespace App\Filament\Resources\GoodsResource\Pages;

use App\Filament\Resources\GoodsResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditGoods extends EditRecord
{
    protected static string $resource = GoodsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
