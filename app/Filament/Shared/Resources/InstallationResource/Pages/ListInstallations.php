<?php

namespace App\Filament\Shared\Resources\InstallationResource\Pages;

use App\Filament\Shared\Resources\InstallationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInstallations extends ListRecords
{
    protected static string $resource = InstallationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
