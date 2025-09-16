<?php

namespace App\Filament\Resources\WorkCenterResource\Pages;

use App\Filament\Resources\WorkCenterResource;
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
