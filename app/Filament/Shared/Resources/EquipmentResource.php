<?php

namespace App\Filament\Shared\Resources;

use App\Filament\Shared\Resources\EquipmentResource\Pages;
use App\Models\Equipment;
use App\Models\EquipmentBrand;
use App\Models\EquipmentModel;
use Filament\Forms;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Forms\Form;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EquipmentResource extends Resource
{
    protected static ?string $model = Equipment::class;
    protected static ?string $navigationGroup = 'Settings';
    protected static ?int $navigationSort = 5;
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Equipment')
                ->columns(2)
                ->schema([
                    Select::make('equipment_brand_id')->label('Brand')->searchable()->options(fn () => EquipmentBrand::orderBy('name')->pluck('name', 'id'))
                        ->required()->live()->afterStateUpdated(fn (Set $set) => $set('equipment_model_id', null)),
                    Select::make('equipment_model_id')->label('Model')->searchable()->required()
                        ->disabled(fn (Get $get) => ! $get('equipment_brand_id'))
                        ->options(fn (Get $get) => EquipmentModel::where('equipment_brand_id', $get('equipment_brand_id'))
                            ->orderBy('name')->pluck('name', 'id')),
                    Select::make('customer_id')->label('Current owner')->relationship('owner', 'display_name')->searchable()
                        ->required(fn (string $operation) => $operation === 'create')
                        ->disabledOn('edit')->helperText('Use Transfer ownership to change the customer.'),
                    TextInput::make('company')->required(),
                    TextInput::make('identifier')->label('Equipment Identifier')->maxLength(255),
                    TextInput::make('physical_location')->maxLength(255),
                    TextInput::make('operational_status')->maxLength(255),
                    Forms\Components\Textarea::make('technical_notes')->columnSpanFull(),

                    Repeater::make('equi_goods')
                        ->relationship('equi_goods')
                        ->schema([
                            Select::make('goods_id')
                                ->label('Goods')
                                ->relationship('goods', 'name')
                                ->required(),
                            TextInput::make('quantity')
                                ->label('Quantity')
                                ->numeric()
                                ->suffix('Units')
                                ->required(),
                        ])
                        ->columns(2)
                        ->label('Goods')
                        ->addActionLabel('Add Goods'),

                    Repeater::make('equi_asset')
                        ->relationship('equi_asset')
                        ->schema([
                            Select::make('asset_id')
                                ->label('Product')
                                ->relationship('asset', 'name')
                                ->required(),
                            TextInput::make('quantity')
                                ->label('Quantity')
                                ->numeric()
                                ->suffix('Units')
                                ->required(),
                        ])
                        ->columns(2)
                        ->label('Products')
                        ->addActionLabel('Add Product'),
                ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('catalogModel.brand.name')->label('Brand')->getStateUsing(fn (Equipment $record) => $record->catalogModelForDisplay()?->brand?->name ?? $record->brand),
            TextEntry::make('catalogModel.name')->label('Model')->getStateUsing(fn (Equipment $record) => $record->catalogModelForDisplay()?->name ?? $record->model),
            TextEntry::make('company'),
            TextEntry::make('owner.display_name')->label('Current owner')->placeholder('Unassigned'),
            TextEntry::make('physical_location'),
            TextEntry::make('operational_status'),
            TextEntry::make('technical_notes')->columnSpanFull(),
            TextEntry::make('identifier')->label('Equipment Identifier')->placeholder('Not specified'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('catalogModel.brand.name')->label('Brand')->getStateUsing(fn (Equipment $record) => $record->catalogModelForDisplay()?->brand?->name ?? $record->brand),
                TextColumn::make('catalogModel.name')->label('Model')->getStateUsing(fn (Equipment $record) => $record->catalogModelForDisplay()?->name ?? $record->model),
                TextColumn::make('company'),
                TextColumn::make('owner.display_name')->label('Current owner')->placeholder('Unassigned')->searchable(),
                TextColumn::make('identifier')->label('Equipment Identifier')->searchable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('installedProducts')->label('Installed products')
                    ->authorize(fn (Equipment $record): bool => static::canView($record))
                    ->modalContent(fn (Equipment $record) => view('filament.installations.installed-products', [
                        'installedProducts' => $record->installedProducts()->withTraceabilityDetails()->get(),
                    ]))
                    ->modalSubmitAction(false),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEquipment::route('/'),
            'create' => Pages\CreateEquipment::route('/create'),
            'edit' => Pages\EditEquipment::route('/{record}/edit'),
        ];
    }
}
