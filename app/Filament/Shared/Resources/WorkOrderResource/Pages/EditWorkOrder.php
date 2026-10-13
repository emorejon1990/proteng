<?php

namespace App\Filament\Shared\Resources\WorkOrderResource\Pages;

use App\Filament\Shared\Resources\WorkOrderResource;
use App\Models\WorkOrder;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditWorkOrder extends EditRecord
{
    protected static string $resource = WorkOrderResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $order = new WorkOrder;
        $order->forceFill(['type_id' => $data['type_id'] ?? $this->record->type_id]);
        if ($order->isReviewOrder() || $this->record->isReviewOrder()) {
            throw ValidationException::withMessages([
                'data.type_id' => 'Gestiona las órdenes Review desde Órdenes Review.',
            ]);
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
