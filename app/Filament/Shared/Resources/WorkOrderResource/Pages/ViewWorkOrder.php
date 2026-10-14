<?php

namespace App\Filament\Shared\Resources\WorkOrderResource\Pages;

use App\Filament\Shared\Resources\InstallationResource;
use App\Filament\Shared\Resources\WorkOrderResource;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewWorkOrder extends ViewRecord
{
    protected static string $resource = WorkOrderResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('name'), TextEntry::make('type.name'), TextEntry::make('status.name'),
            TextEntry::make('customer.display_name'),
            TextEntry::make('invoice.invoice_number')->label('Invoice'),
            TextEntry::make('installation.status'),
            TextEntry::make('installation.id')->label('View Installation')
                ->url(fn ($record) => $record->installation ? InstallationResource::getUrl('view', ['record' => $record->installation->id]) : null),
            RepeatableEntry::make('workOrderAssets')->schema([TextEntry::make('asset.name'), TextEntry::make('quantity')])->columns(2),
        ]);
    }
}
