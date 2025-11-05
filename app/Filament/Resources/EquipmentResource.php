<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use Filament\Forms\Form;
use App\Models\Equipment;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Repeater;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\EquipmentResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\EquipmentResource\RelationManagers;

class EquipmentResource extends Resource
{
    protected static ?string $model = Equipment::class;

    protected static ?string $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Equipment')->schema([
                    TextInput::make('brand'),

                    TextInput::make('model'),

                    TextInput::make('company'),

                    Repeater::make('equi_goods')
                        ->relationship('equi_goods') // <-- usa el hasMany del pivot-model
                        ->schema([
                            Select::make('goods_id')
                                ->label('Goods')
                                ->relationship('goods', 'name') // usa la relación asset() en el modelo pivote
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
                        ->relationship('equi_asset') // <-- usa el hasMany del pivot-model
                        ->schema([
                            Select::make('asset_id')
                                ->label('Product')
                                ->relationship('asset', 'name') // usa la relación asset() en el modelo pivote
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
                ])->columnSpan(2)->columns(2),
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
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            //
        ];
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
