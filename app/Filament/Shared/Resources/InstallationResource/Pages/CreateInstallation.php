<?php

namespace App\Filament\Shared\Resources\InstallationResource\Pages;

use App\Filament\Shared\Resources\InstallationResource;
use App\Models\Customer;
use App\Services\InstallationService;
use Filament\Resources\Pages\CreateRecord;

class CreateInstallation extends CreateRecord
{
    protected static string $resource = InstallationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['customer_quickbooks_id'] = $data['customer_quickbooks_id']
            ?? Customer::query()->find($data['customer_id'])?->quickbooks_id;

        return $data;
    }

    protected function afterCreate(): void
    {
        $service = app(InstallationService::class);
        $service->syncAssignment($this->record);

        if ($this->record->steps()->count() === 0) {
            $service->cloneTemplateSteps($this->record);
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
