<?php

namespace App\Filament\Shared\Resources\InstallationResource\Pages;

use App\Filament\Shared\Resources\InstallationResource;
use App\Models\Installation;
use App\Services\InstallationProductService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ViewInstallation extends ViewRecord
{
    protected static string $resource = InstallationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('registerInstalledProduct')
                ->label('Register installed product')
                ->authorize(fn () => Gate::allows('update', $this->record))
                ->visible(fn () => $this->record->status === Installation::STATUS_IN_PROGRESS && $this->record->work_order_id)
                ->form([TextInput::make('installationSerial')->label('Product serial')->required()->maxLength(255)])
                ->action(function (array $data, Action $action): void {
                    Gate::authorize('update', $this->record);
                    try {
                        $record = app(InstallationProductService::class)->register($this->record, $data['installationSerial'], auth()->user());
                    } catch (ValidationException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();
                        $action->halt();
                    }
                    Notification::make()->title('Installed serial recorded: '.$record->serial_number)->success()->send();
                }),
        ];
    }
}
