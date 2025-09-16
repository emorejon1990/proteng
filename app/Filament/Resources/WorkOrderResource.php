<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\WOType;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Forms\Form;
use App\Models\WorkOrder;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Repeater;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\SelectColumn;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\WorkOrderResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\WorkOrderResource\RelationManagers;
use App\Filament\Resources\WorkOrderResource\RelationManagers\DistributionsRelationManager;

class WorkOrderResource extends Resource
{
    protected static ?string $model = WorkOrder::class;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Work Order')->schema([
                    TextInput::make('name'),

                    DatePicker::make('date')->displayFormat('m/d/Y')->native(false)->minDate(now()),

                    Select::make('status_id')
                        ->label('Status')
                        ->relationship('status', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),

                    Select::make('type_id')
                        ->label('Type')
                        ->relationship('type', 'name')
                        ->searchable()
                        ->live()
                        ->preload()
                        ->required(),

                    Select::make('asset_id')
                        ->visible(fn (Get $get): bool => $get('type_id') != 2)
                        ->label('Product')
                        ->relationship('asset', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),

                    TextInput::make('quant')->numeric()->suffix('Units')
                        ->visible(fn (Get $get): bool => $get('type_id') != 2),
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
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(function ($record): bool {
                    return $record->status_id == 1;}),
                    Action::make('start')
                        ->icon('heroicon-s-play')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function ($record) {
                            $record->status_id = 3;
                            $record->save();
                        })
                        ->visible(function ($record): bool {
                        return $record->status_id == 2;}),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        if (fn (Get $get): bool => $get('type_id') != 2) {
            return [
                DistributionsRelationManager::class
            ];
        }else {
            return [];
        }

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
