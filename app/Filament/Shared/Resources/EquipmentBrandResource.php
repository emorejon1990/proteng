<?php

namespace App\Filament\Shared\Resources;

use App\Filament\Shared\Resources\EquipmentBrandResource\Pages\ManageEquipmentBrands;
use App\Models\EquipmentBrand;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class EquipmentBrandResource extends Resource
{
    protected static ?string $model = EquipmentBrand::class;

    protected static ?string $navigationGroup = 'Settings';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required()->maxLength(255)
                ->rule(fn (?EquipmentBrand $record) => function (string $attribute, mixed $value, Closure $fail) use ($record) {
                    if (EquipmentBrand::where('name_key', EquipmentBrand::nameKey($value))
                        ->when($record, fn ($query) => $query->whereKeyNot($record->id))->exists()) {
                        $fail('A brand with this name already exists.');
                    }
                }),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([Tables\Columns\TextColumn::make('name')->searchable()->sortable()])
            ->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageEquipmentBrands::route('/')];
    }
}
