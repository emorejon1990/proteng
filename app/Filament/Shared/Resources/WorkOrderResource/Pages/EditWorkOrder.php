<?php

namespace App\Filament\Shared\Resources\WorkOrderResource\Pages;

use App\Filament\Shared\Resources\WorkOrderResource;
use App\Models\WorkOrder;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class EditWorkOrder extends EditRecord
{
    protected static string $resource = WorkOrderResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $order = new WorkOrder;
        $order->forceFill(['type_id' => $data['type_id'] ?? $this->record->type_id]);
        if ($order->isInstallationOrder() || $this->record->isInstallationOrder()) {
            throw ValidationException::withMessages(['data.type_id' => 'Manage this order through the linked Installation flow.']);
        }
        if (Schema::hasColumn('invoices', 'work_order_id') && $this->record->invoice && (int) $order->type_id !== (int) $this->record->type_id) {
            throw ValidationException::withMessages(['data.type_id' => 'An invoice-linked operation cannot change its type.']);
        }
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
