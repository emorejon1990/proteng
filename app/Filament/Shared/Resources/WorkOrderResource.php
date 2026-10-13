<?php

namespace App\Filament\Shared\Resources;

use App\Filament\Manager\Resources\InvoiceResource;
use App\Filament\Pages\ReviewOrders;
use App\Filament\Shared\Resources\WorkOrderResource\Pages;
use App\Filament\Shared\Resources\WorkOrderResource\RelationManagers\DistributionsRelationManager;
use App\Models\Asset;
use App\Models\WorkOrder;
use App\Services\ProductionCatalog;
use App\Services\ReviewWorkOrderService;
use App\Services\WorkOrderLifecycleService;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class WorkOrderResource extends Resource
{
    protected static ?string $model = WorkOrder::class;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user && $user->hasRole(['Admin', 'Manager']);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Work Order')->schema([
                    // TextInput::make('name'),

                    DatePicker::make('date')->displayFormat('m/d/Y')->native(false)->minDate(now()),

                    // Select::make('status_id')
                    //     ->label('Status')
                    //     ->relationship('status', 'name')
                    //     ->searchable()
                    //     ->preload()
                    //     ->required(),

                    Select::make('type_id')
                        ->label('Type')
                        ->relationship('type', 'name', modifyQueryUsing: fn (Builder $query) => $query->whereRaw('LOWER(TRIM(name)) <> ?', ['review']))
                        ->searchable()
                        ->live()
                        ->preload()
                        ->required(),

                    Select::make('customer_quickbooks_id')
                        ->label('Customer')
                        ->relationship('customer', 'display_name')
                        ->searchable()
                        ->preload()
                        ->required(fn (Get $get): bool => (int) $get('type_id') === app(ProductionCatalog::class)->id('distribution_type'))
                        ->visible(fn (Get $get): bool => (int) $get('type_id') === app(ProductionCatalog::class)->id('distribution_type')),

                    Select::make('asset_id')
                        ->visible(fn (Get $get): bool => (int) $get('type_id') !== app(ProductionCatalog::class)->id('distribution_type'))
                        ->label('Product')
                        ->relationship('asset', 'name')
                        ->searchable()
                        ->preload()
                        ->required(fn (Get $get): bool => (int) $get('type_id') !== app(ProductionCatalog::class)->id('distribution_type')),

                    TextInput::make('quant')->numeric()->integer()->minValue(1)->suffix('Units')
                        ->required(fn (Get $get): bool => (int) $get('type_id') !== app(ProductionCatalog::class)->id('distribution_type'))
                        ->visible(fn (Get $get): bool => (int) $get('type_id') !== app(ProductionCatalog::class)->id('distribution_type')),

                    Repeater::make('workOrderAssets')
                        ->relationship('workOrderAssets') // <-- usa el hasMany del pivot-model
                        ->schema([
                            Select::make('asset_id')
                                ->label('Product')
                                ->relationship('asset', 'name') // usa la relación asset() en el modelo pivote
                                ->distinct()
                                ->required(),
                            TextInput::make('quantity')
                                ->label('Quantity')
                                ->numeric()
                                ->integer()->minValue(1)
                                ->suffix('Units')
                                ->required(),
                        ])
                        ->columns(2)
                        ->label('Products')
                        ->addActionLabel('Add Product')
                        ->visible(fn (Get $get): bool => (int) $get('type_id') === app(ProductionCatalog::class)->id('distribution_type')),
                ])->columnSpan(2)->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('asset_name')->label('Product'),
                TextColumn::make('date')->dateTime('m/d/Y'),
                TextColumn::make('quant')->suffix('-Units')->label('Quantity'),
                TextColumn::make('status_name')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'New' => 'info',
                        'Cancelled' => 'danger',
                        'inProgress' => 'warning',
                        'Approved' => 'primary',
                        'Ready' => 'success',
                        default => 'secondary',
                    }),
                TextColumn::make('type_name')->label('Type'),
                TextColumn::make('review_receiving_open')->label('Review reception')
                    ->formatStateUsing(fn ($state, WorkOrder $record) => $record->isReviewOrder() ? ($state ? 'Open' : 'Closed') : '—'),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(function ($record): bool {
                        return ! $record->isReviewOrder() && (int) $record->status_id === app(ProductionCatalog::class)->id('new_order_status');
                    }),
                Action::make('manageReview')
                    ->label('Gestionar Review')
                    ->url(fn () => ReviewOrders::getUrl())
                    ->visible(fn (WorkOrder $record): bool => $record->isReviewOrder()),
                Action::make('createInvoice')
                    ->label('Create Invoice')
                    ->icon('heroicon-o-document-plus')
                    ->url(function ($record): string {
                        $items = $record->workOrderAssets
                            ->map(fn ($item) => [
                                'asset_id' => $item->asset_id,
                                'qty' => $item->quantity,
                                'price' => 0,
                            ])
                            ->values()
                            ->toArray();

                        return InvoiceResource::getUrl('create', [
                            'customer_id' => $record->customer?->id,
                            'items' => $items,
                        ]);
                    })
                    ->visible(function ($record): bool {
                        return (int) $record->type_id === app(ProductionCatalog::class)->id('distribution_type')
                            && (int) $record->status_id === app(ProductionCatalog::class)->id('ready_order_status')
                            && $record->customer?->id
                            && Filament::getCurrentPanel()?->getId() === 'manager'
                            && Auth::user()?->hasRole(['Manager']);
                    }),
                Action::make('start')
                    ->icon('heroicon-s-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        if ($record->isReviewOrder()) {
                            app(ReviewWorkOrderService::class)->start($record->id, Auth::user());

                            return;
                        }
                        app(WorkOrderLifecycleService::class)->start($record->id, Auth::user());
                    })
                    ->visible(function ($record): bool {
                        return ! $record->isReviewOrder() && (int) $record->status_id === app(ProductionCatalog::class)->id('approved_order_status');
                    }),
                Action::make('approved')
                    ->icon('heroicon-s-hand-thumb-up')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        if ($record->isReviewOrder()) {
                            app(ReviewWorkOrderService::class)->approve($record->id, Auth::user());

                            return;
                        }
                        app(WorkOrderLifecycleService::class)->approve($record->id, Auth::user());
                    })
                    ->visible(function ($record): bool {
                        return ! $record->isReviewOrder() && (int) $record->status_id === app(ProductionCatalog::class)->id('new_order_status');
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {

        return [
            // DistributionsRelationManager::class
        ];

    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWorkOrders::route('/'),
            'create' => Pages\CreateWorkOrder::route('/create'),
            'edit' => Pages\EditWorkOrder::route('/{record}/edit'),
        ];
    }
}
