<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Goods;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\ImageColumn;
use Filament\Forms\Components\FileUpload;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\GoodsResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\GoodsResource\RelationManagers;

class GoodsResource extends Resource
{
    protected static ?string $model = Goods::class;

    protected static ?string $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name'),
                TextInput::make('link'),
                Select::make('category')
                    ->options([
                        'Fisical' => 'Fisical',
                        'Electrical' => 'Electrical',
                    ]),
                FileUpload::make('picture')
                    ->image()
                    ->directory('goods')
                    ->visibility('public'),
                TextInput::make('picture_url')
                    ->label('Picture Link')
                    ->url()
                    ->maxLength(255)
                    ->helperText('If you use this field, the field picture goes to be null.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_display')
                    ->label('Picture')
                    ->getStateUsing(function ($record) {
                        if ($record->picture_url) {
                            return $record->picture_url; // usa la URL externa
                        } elseif ($record->picture) {
                            return asset('storage/' . $record->picture); // usa la imagen local
                        }
                        return null;
                    })
                    ->square(),
                TextColumn::make('name'),
                TextColumn::make('link')
                    ->formatStateUsing(fn ($state) => $state ? '🔗 See Product' : '')
                    ->url(fn ($record) => $record->link) // lo hace clickeable
                    ->openUrlInNewTab() // abre en nueva pestaña
                    ->limit(30),
                TextColumn::make('category'),
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
            'index' => Pages\ListGoods::route('/'),
            'create' => Pages\CreateGoods::route('/create'),
            'edit' => Pages\EditGoods::route('/{record}/edit'),
        ];
    }
}
