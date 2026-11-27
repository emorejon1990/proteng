<?php

namespace App\Filament\Shared\Resources\EquipmentResource\Pages;

use App\Filament\Shared\Resources\EquipmentResource;
use App\Models\Customer;
use App\Services\EquipmentOwnershipService;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EditEquipment extends EditRecord
{
    protected static string $resource = EquipmentResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['equipment_brand_id'] = $this->record->catalogModelForDisplay()?->equipment_brand_id;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->record = app(EquipmentOwnershipService::class)->update($record, $data, auth()->user());
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('ownershipHistory')->label('Ownership history')
                ->authorize(fn () => Gate::allows('view', $this->record))
                ->modalContent(fn () => view('filament.equipment.ownership-history', [
                    'transfers' => $this->record->ownershipTransfers()->with(['previousCustomer', 'newCustomer', 'user'])->get(),
                ]))->modalSubmitAction(false),
            Actions\Action::make('transferOwnership')->label('Transfer ownership')
                ->authorize(fn () => Gate::allows('transfer', $this->record))
                ->requiresConfirmation()
                ->form([
                    Select::make('customer_id')->label('New owner')->required()->searchable()
                        ->options(fn () => Customer::orderBy('display_name')->pluck('display_name', 'id')),
                    Textarea::make('reason')->maxLength(2000),
                ])
                ->action(function (array $data, Actions\Action $action) {
                    try {
                        app(EquipmentOwnershipService::class)->transfer($this->record, (int) $data['customer_id'], auth()->user(), $data['reason'] ?? null);
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Equipment could not be transferred')->body($exception->getMessage())->danger()->send();
                        $action->halt();
                    }
                    $this->record->refresh();
                    $this->refreshFormData(['customer_id']);
                    Notification::make()->title('Ownership transferred')->success()->send();
                }),
            Actions\DeleteAction::make()
                ->using(fn () => app(EquipmentOwnershipService::class)->delete($this->record, auth()->user())),
        ];
    }
}
