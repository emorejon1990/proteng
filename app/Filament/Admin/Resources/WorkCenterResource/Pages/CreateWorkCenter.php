<?php

namespace App\Filament\Admin\Resources\WorkCenterResource\Pages;

use App\Filament\Admin\Resources\WorkCenterResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkCenter extends CreateRecord
{
    protected static string $resource = WorkCenterResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
