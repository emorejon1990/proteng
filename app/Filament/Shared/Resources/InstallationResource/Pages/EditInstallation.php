<?php

namespace App\Filament\Shared\Resources\InstallationResource\Pages;

use App\Filament\Shared\Resources\InstallationResource;
use App\Services\InstallationProgressService;
use App\Services\InstallationService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditInstallation extends EditRecord
{
    protected static string $resource = InstallationResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        [$installation] = app(InstallationProgressService::class)->lockRecords($record);
        $identityFields = [
            'equipment_id' => 'equipment_id',
            'customer_id' => 'customer_id',
            'customer_quickbooks_id' => 'customer_id',
        ];
        $errors = [];
        foreach ($identityFields as $attribute => $field) {
            if (array_key_exists($attribute, $data) && (string) $data[$attribute] !== (string) $installation->$attribute) {
                $errors['data.'.$field] = 'The customer and equipment are fixed once an installed product is recorded.';
            }
        }
        if ($errors && $installation->installedProducts()->lockForUpdate()->first()) {
            throw ValidationException::withMessages($errors);
        }

        $this->record = $installation;

        return parent::handleRecordUpdate($installation, $data);
    }

    protected function afterSave(): void
    {
        app(InstallationService::class)->syncAssignment($this->record);
        if ($this->record->status === 'in_progress') {
            app(InstallationProgressService::class)->startIfNeeded($this->record);
        }
        app(InstallationProgressService::class)->completeIfAllDone($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
