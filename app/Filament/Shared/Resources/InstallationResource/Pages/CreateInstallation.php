<?php

namespace App\Filament\Shared\Resources\InstallationResource\Pages;

use App\Filament\Shared\Resources\InstallationResource;
use App\Models\Customer;
use App\Services\InstallationProgressService;
use App\Services\InstallationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateInstallation extends CreateRecord
{
    protected static string $resource = InstallationResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordCreation(array $data): Model
    {
        return app(InstallationService::class)->create($data, cloneSteps: false);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['customer_quickbooks_id'] = $data['customer_quickbooks_id']
            ?? Customer::query()->find($data['customer_id'])?->quickbooks_id;

        return $data;
    }

    protected function afterCreate(): void
    {
        $service = app(InstallationService::class);

        if ($this->record->steps()->count() === 0) {
            $service->cloneTemplateSteps($this->record);
        }
        $progress = app(InstallationProgressService::class);
        if ($this->record->status === 'in_progress') {
            $progress->startIfNeeded($this->record);
        } elseif ($this->record->status === 'completed' && ! $progress->completeIfAllDone($this->record)) {
            throw ValidationException::withMessages(['data.status' => 'Complete all installation steps before finishing.']);
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
