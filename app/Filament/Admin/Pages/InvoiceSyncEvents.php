<?php

namespace App\Filament\Admin\Pages;

use App\Models\InvoiceEvent;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvoiceSyncEvents extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'QuickBooks';

    protected static string $view = 'filament.admin.pages.invoice-sync-events';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('Admin') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table->query(InvoiceEvent::query())->columns([
            TextColumn::make('created_at')->dateTime()->sortable(),
            TextColumn::make('event')->searchable(),
            TextColumn::make('invoice.invoice_number')->searchable(),
            TextColumn::make('metadata.quickbooks_id')->label('QuickBooks ID'),
            TextColumn::make('work_order_id'),
            TextColumn::make('installation_id'),
            TextColumn::make('metadata.error')->wrap(),
            TextColumn::make('metadata.reason')->wrap(),
        ])->filters([
            SelectFilter::make('event')->options(['Invoice Sync Failed' => 'Sync Failed', 'Invoice Sync Conflict' => 'Sync Conflict',
                'Invoice Source Unavailable' => 'Source Unavailable', 'Invoice Classified' => 'Classified']),
        ])->defaultSort('id', 'desc');
    }
}
