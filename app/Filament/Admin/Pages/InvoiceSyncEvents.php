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
            TextColumn::make('metadata.quickbooks_id')->label('QuickBooks ID')->getStateUsing(fn (InvoiceEvent $record) => $record->metadata['quickbooks_id'] ?? $record->invoice?->quickbooks_id),
            TextColumn::make('work_order_id'),
            TextColumn::make('installation_id'),
            TextColumn::make('metadata.error')->wrap(),
            TextColumn::make('metadata.reason')->wrap(),
            TextColumn::make('metadata.entity'),
            TextColumn::make('metadata.line_type'),
            TextColumn::make('metadata.line_index')->label('Line index (0-based)'),
            TextColumn::make('metadata.reference_format'),
            TextColumn::make('metadata.synced')->label('Synchronized'),
            TextColumn::make('metadata.failed')->label('Skipped'),
        ])->filters([
            SelectFilter::make('event')->options(['Invoice Sync Failed' => 'Sync Failed', 'Invoice Sync Conflict' => 'Sync Conflict',
                'Invoice Sync Skipped' => 'Data error', 'Invoice Sync Partial' => 'Partial', 'Invoice Sync Stopped' => 'Stopped', 'Invoice Sync Completed' => 'Completed', 'Invoice Imported' => 'Imported', 'Invoice Updated' => 'Updated', 'Invoice Unchanged' => 'Unchanged', 'Invoice Source Unavailable' => 'Source Unavailable', 'Invoice Classified' => 'Classified']),
        ])->defaultSort('id', 'desc');
    }
}
