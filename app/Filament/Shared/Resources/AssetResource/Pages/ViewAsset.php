<?php

namespace App\Filament\Shared\Resources\AssetResource\Pages;

use App\Filament\Shared\Resources\AssetResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewAsset extends ViewRecord
{
    protected static string $resource = AssetResource::class;

    protected static string $view = 'filament.resources.asset.pages.view-asset';

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }
}
