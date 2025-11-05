<?php

namespace App\Filament\Resources\ManualResource\Pages;

use App\Filament\Resources\ManualResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewManual extends ViewRecord
{
    protected static string $resource = ManualResource::class;

    protected static string $view = 'filament.resources.manual.pages.view-manual';

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }
}
