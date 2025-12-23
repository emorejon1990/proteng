<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\WorkCenterResource\Pages;
use App\Filament\Admin\Resources\WorkCenterResource\RelationManagers;
use App\Models\WorkCenter;
use Filament\Forms;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class WorkCenterResource extends Resource
{
    protected static ?string $model = WorkCenter::class;

    protected static ?string $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
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
            'index' => Pages\ListWorkCenters::route('/'),
            'create' => Pages\CreateWorkCenter::route('/create'),
            'edit' => Pages\EditWorkCenter::route('/{record}/edit'),
        ];
    }
}
