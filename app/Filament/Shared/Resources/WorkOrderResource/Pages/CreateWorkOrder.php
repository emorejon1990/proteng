<?php

namespace App\Filament\Shared\Resources\WorkOrderResource\Pages;

use App\Filament\Shared\Resources\WorkOrderResource;
use App\Models\WorkOrder;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateWorkOrder extends CreateRecord
{
    protected static string $resource = WorkOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $order = new WorkOrder;
        $order->forceFill(['type_id' => $data['type_id'] ?? null]);
        if ($order->isReviewOrder()) {
            throw ValidationException::withMessages([
                'data.type_id' => 'Las órdenes Review se crean al incorporar rechazos de calidad.',
            ]);
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
