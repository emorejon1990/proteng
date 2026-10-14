<?php

namespace App\Filament\Shared\Resources;

use App\Filament\Shared\Resources\AssetResource\Pages;
use App\Models\Asset;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AssetResource extends Resource
{
    protected static ?string $model = Asset::class;

    protected static ?string $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name'),
                TextInput::make('quickbooks_id')->label('QuickBooks Item ID')->numeric()->unique(ignoreRecord: true)
                    ->helperText('Local mapping. Invoice synchronization verifies the Item type before using this Asset.'),
                TextInput::make('weight'),
                TextInput::make('weight_tolerance'),
                RichEditor::make('description'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('quickbooks_id')->label('QuickBooks Item ID')->searchable(),
                TextColumn::make('quickbooks_item_type'),
                TextColumn::make('quickbooks_active')->badge(),
                TextColumn::make('weight'),
                TextColumn::make('weight_tolerance'),
                TextColumn::make('description')->html()->limit(50, end: ' (more)'),
            ])
            ->filters([
                //
            ])
            ->recordUrl(
                fn (Model $record): ?string => static::canView($record) ? static::getUrl('view', ['record' => $record]) : null,
            )
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
            'index' => Pages\ListAssets::route('/'),
            'create' => Pages\CreateAsset::route('/create'),
            'edit' => Pages\EditAsset::route('/{record}/edit'),
            'view' => Pages\ViewAsset::route('/{record}'),
        ];
    }
}
