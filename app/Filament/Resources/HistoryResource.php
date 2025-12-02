<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\History;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\HistoryResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\HistoryResource\RelationManagers;

class HistoryResource extends Resource
{
    protected static ?string $model = History::class;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Products History';

    protected static ?string $pluralModelLabel = 'History';

    protected static ?string $modelLabel = 'Register';

    // public static function form(Form $form): Form
    // {
    //     return $form
    //         ->schema([
    //             Forms\Components\TextInput::make('products_id')
    //                 ->required()
    //                 ->numeric(),
    //             Forms\Components\Textarea::make('process')
    //                 ->required()
    //                 ->columnSpanFull(),
    //             Forms\Components\DateTimePicker::make('date')
    //                 ->required(),
    //             Forms\Components\TextInput::make('user_id')
    //                 ->required()
    //                 ->numeric(),
    //             Forms\Components\TextInput::make('location')
    //                 ->required()
    //                 ->maxLength(255),
    //             Forms\Components\Textarea::make('description')
    //                 ->required()
    //                 ->columnSpanFull(),
    //         ]);
    // }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Número de serie del producto
                TextColumn::make('product.serial')
                    ->label('Product (Serial)')
                    ->searchable()
                    ->sortable(),

                // Tipo de proceso
                TextColumn::make('process')
                    ->label('Process')
                    ->searchable()
                    ->sortable(),

                // Ubicación
                TextColumn::make('location')
                    ->label('Location')
                    ->searchable(),

                // Fecha del evento
                TextColumn::make('date')
                    ->dateTime('d/m/Y')
                    ->label('Date')
                    ->sortable(),

                // Usuario que realizó el cambio
                TextColumn::make('user.name')
                    ->label('User')
                    ->sortable(),

                // Descripción del proceso
                TextColumn::make('description')
                    ->label('Description')
                    ->limit(50),
            ])->defaultSort('date', 'desc')
            ->filters([
                SelectFilter::make('product_id')
                    ->relationship('product', 'serial')
                    ->searchable()
                    ->label('Product')
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
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
            'index' => Pages\ListHistories::route('/'),
        ];
    }
}
