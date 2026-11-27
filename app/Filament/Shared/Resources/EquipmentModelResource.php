<?php

namespace App\Filament\Shared\Resources;

use App\Filament\Shared\Resources\EquipmentModelResource\Pages\ManageEquipmentModels;
use App\Models\EquipmentBrand;
use App\Models\EquipmentModel;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class EquipmentModelResource extends Resource
{
    protected static ?string $model = EquipmentModel::class;

    protected static ?string $navigationGroup = 'Settings';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('equipment_brand_id')->label('Brand')->relationship('brand', 'name')
                ->searchable()->required()->live()->disabledOn('edit'),
            TextInput::make('name')->required()->maxLength(255)
                ->rule(fn (Get $get, ?EquipmentModel $record) => function (string $attribute, mixed $value, Closure $fail) use ($get, $record) {
                    if (EquipmentModel::where('equipment_brand_id', $record?->equipment_brand_id ?? $get('equipment_brand_id'))
                        ->where('name_key', EquipmentBrand::nameKey($value))
                        ->when($record, fn ($query) => $query->whereKeyNot($record->id))->exists()) {
                        $fail('A model with this name already exists for this brand.');
                    }
                }),
            Toggle::make('guide_needs_review')->label('Guide needs review')->live()
                ->helperText('Compare the original machine guides before marking this guide ready.'),
            Repeater::make('templateSteps')->relationship()
                ->label('Shared installation guide')->defaultItems(0)
                ->minItems(fn (Get $get, ?EquipmentModel $record) => $record?->guide_needs_review && ! $get('guide_needs_review') ? 1 : 0)
                ->schema([
                    TextInput::make('title')->required()->maxLength(255),
                    TextInput::make('sort_order')->integer()->minValue(1)->default(1)->required(),
                    Textarea::make('description')->columnSpanFull(),
                    Toggle::make('is_required')->default(true),
                    FileUpload::make('img')->label('Picture')->image()->disk('public')
                        ->visibility('public')->directory('equipment-steps'),
                ])->columns(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('brand.name')->searchable()->label('Brand'),
            Tables\Columns\TextColumn::make('name')->searchable(),
            Tables\Columns\IconColumn::make('guide_needs_review')->boolean(),
        ])->actions([
            Tables\Actions\Action::make('legacyGuides')->label('Original machine guides')
                ->authorize(fn (EquipmentModel $record) => static::canView($record))
                ->modalContent(fn (EquipmentModel $record) => view('filament.equipment.original-guides', [
                    'machines' => $record->machines()->whereHas('installationTemplateSteps')
                        ->with('installationTemplateSteps')->orderBy('id')->get(),
                ]))->modalSubmitAction(false),
            Tables\Actions\EditAction::make()->databaseTransaction(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageEquipmentModels::route('/')];
    }
}
