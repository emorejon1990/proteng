<?php

namespace App\Filament\Shared\Resources\EquipmentResource\Pages;

use App\Filament\Shared\Resources\EquipmentResource;
use App\Services\EquipmentOwnershipService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateEquipment extends CreateRecord
{
    protected static string $resource = EquipmentResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordCreation(array $data): Model
    {
        return app(EquipmentOwnershipService::class)->create($data, auth()->user());
    }
}
