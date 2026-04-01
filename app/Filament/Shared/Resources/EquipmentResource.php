<?php

namespace App\Filament\Shared\Resources;

use App\Filament\Shared\Resources\EquipmentResource\Pages;
use App\Models\Equipment;
use Filament\Forms;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Form;
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
                    TextInput::make('brand'),
                    TextInput::make('model'),
                    TextInput::make('company'),

                    Repeater::make('installationTemplateSteps')
                        ->relationship('installationTemplateSteps')
                        ->label('Installation Template Steps')
                        ->columns(2)
                        ->schema([
                            TextInput::make('title')->required(),
                            TextInput::make('sort_order')->numeric()->default(1)->required(),
                            Forms\Components\Textarea::make('description')->columnSpanFull(),
                            Forms\Components\Toggle::make('is_required')->default(true),
                            FileUpload::make('img')
                                ->label('Picture')
                                ->image()
                                ->disk('public')
                                ->visibility('public')
                                ->directory('equipment-steps'),
                            ])
                        ->addActionLabel('Add Installation Step')
                        ->columnSpanFull(),

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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('brand'),
                TextColumn::make('model'),
                TextColumn::make('company'),
            ])
            ->actions([
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
