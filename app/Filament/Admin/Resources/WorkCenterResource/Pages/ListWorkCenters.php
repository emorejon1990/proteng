<?php

namespace App\Filament\Admin\Resources\WorkCenterResource\Pages;

use App\Filament\Admin\Resources\WorkCenterResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWorkCenters extends ListRecords
{
    protected static string $resource = WorkCenterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
