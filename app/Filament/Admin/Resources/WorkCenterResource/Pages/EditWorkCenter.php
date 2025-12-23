<?php

namespace App\Filament\Admin\Resources\WorkCenterResource\Pages;

use App\Filament\Admin\Resources\WorkCenterResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditWorkCenter extends EditRecord
{
    protected static string $resource = WorkCenterResource::class;

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
