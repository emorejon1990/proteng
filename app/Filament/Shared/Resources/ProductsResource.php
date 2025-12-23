<?php

namespace App\Filament\Shared\Resources;

use Filament\Tables;
use App\Models\Products;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Checkbox;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use App\Filament\Shared\Resources\ProductsResource\Pages;

class ProductsResource extends Resource
{
    protected static ?string $model = Products::class;
    protected static ?int $navigationSort = 3;
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
                TextInput::make('serial')
                    ->required(),

                Select::make('assambly_by')
                    ->label('Assambler')
                    ->relationship('assambler', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Checkbox::make('assambled'),

                Select::make('fill_by')
                    ->label('Filler')
                    ->relationship('filler', 'name')
                    ->searchable()
                    ->preload(),

                Checkbox::make('filled'),

                Select::make('quality_by')
                    ->label('Quality Checker')
                    ->relationship('qualityChecker', 'name')
                    ->searchable()
                    ->preload(),

                Checkbox::make('qualifiled'),

                Select::make('asset_id')
                    ->label('Product')
                    ->relationship('asset', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('status_id')
                    ->label('Status')
                    ->relationship('status', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('location_id')
                    ->label('Location')
                    ->relationship('location', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('weight')
                    ->numeric(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('asset_name')->label('Product'),
                TextColumn::make('serial'),
                TextColumn::make('weight')->suffix('g'),
                TextColumn::make('assambler_name')->label('Assambler'),
                TextColumn::make('filler_name')->label('Filler'),
                TextColumn::make('quality_checker_name')->label('Quality Checker'),
                TextColumn::make('status_name')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'danger',
                        'Waiting' => 'info',
                        'Ready' => 'success',
                        default => 'secondary',
                    }),
                TextColumn::make('location_name')->label('Location'),
            ])
            ->filters([
                // Agregá filtros si querés
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
            // Añadí RelationManagers si usás relaciones hasMany o morphMany
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProducts::route('/create'),
            'edit' => Pages\EditProducts::route('/{record}/edit'),
        ];
    }
}
