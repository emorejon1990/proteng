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

        $steps = $this->record->steps()->orderBy('id')->get();
        if ($steps->isEmpty()) {
            $service->cloneTemplateSteps($this->record);
        } elseif ($this->record->equipment->equipment_model_id) {
            $definition = fn ($step) => [
                'title' => $step['title'], 'description' => $step['description'],
                'sort_order' => (int) $step['sort_order'], 'is_required' => (bool) $step['is_required'], 'img' => $step['img'],
            ];
            if ($steps->map($definition)->all() !== array_map($definition, $service->templateStepData($this->record->equipment))) {
                throw ValidationException::withMessages(['data.equipment_id' => 'The model guide changed. Reselect the equipment to load the current steps.']);
            }
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
