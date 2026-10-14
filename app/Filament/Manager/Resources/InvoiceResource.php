<?php

namespace App\Filament\Manager\Resources;

use App\Filament\Manager\Resources\InvoiceResource\Pages;
use App\Filament\Shared\Resources\WorkOrderResource;
use App\Models\Equipment;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceClassificationService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(['Admin', 'Manager']) && ! auth()->user()?->hasRole('Customer');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema(collect(['invoice_number', 'quickbooks_id', 'total', 'balance', 'status', 'operation_type', 'operation_status', 'sync_error'])
            ->map(fn ($field) => Forms\Components\TextInput::make($field)->disabled())->all());
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('invoice_number')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('quickbooks_id')->searchable()->toggleable(),
            Tables\Columns\TextColumn::make('customer.display_name')->searchable(),
            Tables\Columns\TextColumn::make('issued_at')->date()->sortable(),
            Tables\Columns\TextColumn::make('due_at')->date(),
            Tables\Columns\TextColumn::make('total')->money(fn (Invoice $record) => $record->currency_code ?: 'USD'),
            Tables\Columns\TextColumn::make('balance')->money(fn (Invoice $record) => $record->currency_code ?: 'USD'),
            Tables\Columns\TextColumn::make('status')->label('Accounting Status')->badge(),
            Tables\Columns\TextColumn::make('operation_type')->badge(),
            Tables\Columns\TextColumn::make('operation_status')->badge()->color(fn ($state) => in_array($state, ['sync_conflict', 'requires_review']) ? 'danger' : 'success'),
            Tables\Columns\TextColumn::make('sync_status')->badge(),
            Tables\Columns\TextColumn::make('workOrder.name')->label('Linked Work Order'),
        ])->filters([
            Tables\Filters\SelectFilter::make('customer_id')->relationship('customer', 'display_name')->searchable(),
            Tables\Filters\SelectFilter::make('status')->options(['paid' => 'Paid', 'open' => 'Unpaid', 'void' => 'Void']),
            Tables\Filters\SelectFilter::make('operation_type')->options(['distribution' => 'Distribution', 'installation' => 'Installation']),
            Tables\Filters\SelectFilter::make('operation_status')->options(['unclassified' => 'Unclassified', 'created' => 'Created', 'requires_review' => 'Requires Review', 'sync_conflict' => 'Sync Conflict']),
            Tables\Filters\Filter::make('date')->form([Forms\Components\DatePicker::make('from'), Forms\Components\DatePicker::make('until')])
                ->query(fn (Builder $query, array $data) => $query->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('issued_at', '>=', $date))->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('issued_at', '<=', $date))),
        ])->actions([
            Tables\Actions\ViewAction::make()->modalContent(fn (Invoice $record) => view('filament.invoices.details', ['invoice' => $record])),
            Tables\Actions\Action::make('classify')->label('Classify Invoice')->modalSubmitActionLabel('Confirm')
                ->authorize('classify')->visible(fn (Invoice $record) => ! $record->work_order_id && ! $record->operation_type)
                ->modalContent(fn (Invoice $record) => view('filament.invoices.details', ['invoice' => $record]))
                ->form([
                    Forms\Components\Select::make('operation_type')->options(['distribution' => 'Distribution', 'installation' => 'Installation'])->required()->live(),
                    Forms\Components\Select::make('equipment_id')->options(fn () => Equipment::orderBy('model')->pluck('model', 'id'))->searchable()
                        ->visible(fn (Get $get) => $get('operation_type') === 'installation')->required(fn (Get $get) => $get('operation_type') === 'installation'),
                    Forms\Components\Select::make('inst_manager_user_id')->label('Installation Manager')->options(fn () => User::role('Inst_Manager')->pluck('name', 'id'))->searchable()
                        ->visible(fn (Get $get) => $get('operation_type') === 'installation')->required(fn (Get $get) => $get('operation_type') === 'installation'),
                    Forms\Components\Select::make('worker_user_id')->options(fn () => User::role('Worker')->pluck('name', 'id'))->searchable()
                        ->visible(fn (Get $get) => $get('operation_type') === 'installation')->required(fn (Get $get) => $get('operation_type') === 'installation'),
                ])->action(fn (Invoice $record, array $data) => app(InvoiceClassificationService::class)->classify($record->id, $data['operation_type'], auth()->user(), $data)),
            Tables\Actions\Action::make('workOrder')->label(fn (Invoice $record) => ucfirst($record->operation_type ?? '').' Created / View Work Order')
                ->visible(fn (Invoice $record) => (bool) $record->work_order_id)->url(fn (Invoice $record) => WorkOrderResource::getUrl('view', ['record' => $record->work_order_id])),
            Tables\Actions\Action::make('pdf')->label('Print Invoice')->url(fn (Invoice $record) => route('invoices.pdf', $record))->openUrlInNewTab(),
            Tables\Actions\Action::make('packing')->label('Print Packing Slip')->visible(fn (Invoice $record) => $record->operation_type === 'distribution' && $record->work_order_id)
                ->url(fn (Invoice $record) => route('invoices.packing-slip', $record))->openUrlInNewTab(),
        ])->bulkActions([])->defaultSort('issued_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListInvoices::route('/')];
    }
}
