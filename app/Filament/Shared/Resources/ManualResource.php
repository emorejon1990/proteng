<?php

namespace App\Filament\Shared\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Manual;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Filament\Forms\Components\RichEditor;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Shared\Resources\ManualResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Shared\Resources\ManualResource\RelationManagers;

class ManualResource extends Resource
{
    protected static ?string $model = Manual::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Manual')->schema([
                    Section::make()->schema([
                        Select::make('equipment_id')
                            ->label('Equipment')
                            ->relationship('equipment', 'brand')
                            ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->brand} - {$record->model}")
                            ->searchable()
                            ->live()
                            ->preload()
                            ->required(),
                    ])->columnSpan(2)->columns(2),

                    RichEditor::make('descript')
                        // ->json()
                        ->fileAttachmentsDirectory('manual')
                        ->fileAttachmentsVisibility('public'),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('equipment_name')
                    ->label('Equipment')
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->recordUrl(
                fn (Model $record): string => route('filament.admin.resources.manuals.view', ['record' => $record]),
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
            'index' => Pages\ListManuals::route('/'),
            'create' => Pages\CreateManual::route('/create'),
            'view' => Pages\ViewManual::route('/{record}'),
            'edit' => Pages\EditManual::route('/{record}/edit'),
        ];
    }
}
