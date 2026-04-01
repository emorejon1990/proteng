<?php

namespace App\Filament\Shared\Resources\InstallationResource\Pages;

use App\Filament\Shared\Resources\InstallationResource;
use App\Services\InstallationProgressService;
use App\Services\InstallationService;
use Filament\Resources\Pages\EditRecord;

class EditInstallation extends EditRecord
{
    protected static string $resource = InstallationResource::class;

    protected function afterSave(): void
    {
        app(InstallationService::class)->syncAssignment($this->record);
        app(InstallationProgressService::class)->completeIfAllDone($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
