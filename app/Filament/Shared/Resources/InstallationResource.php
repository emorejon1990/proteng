<?php

namespace App\Filament\Shared\Resources;

use App\Filament\Shared\Resources\InstallationResource\Pages;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\Installation;
use App\Models\User;
use App\Services\InstallationService;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class InstallationResource extends Resource
{
    protected static ?string $model = Installation::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';
    protected static ?string $navigationGroup = 'Operations';
    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRol(['Admin', 'Manager', 'Inst_Manager']) ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Installation')
                ->columns(2)
                ->schema([
                    Select::make('equipment_id')
                        ->label('Equipment')
                        ->relationship('equipment', 'model')
                        ->getOptionLabelFromRecordUsing(fn (Equipment $record): string => trim("{$record->brand} {$record->model}"))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required()
                        ->afterStateUpdated(function (?int $state, Set $set, string $operation): void {
                            if ($operation !== 'create' || ! $state) {
                                return;
                            }

                            $template = \App\Models\EquipmentInstallationStep::query()
                                ->where('equipment_id', $state)
                                ->orderBy('sort_order')
                                ->get()
                                ->map(fn ($step) => [
                                    'title' => $step->title,
                                    'description' => $step->description,
                                    'sort_order' => $step->sort_order,
                                    'is_required' => $step->is_required,
                                    'img' => $step->img,
                                    'is_done' => false,
                                    'done_at' => null,
                                    'notes' => null,
                                ])
                                ->toArray();

                            $set('steps', $template);
                        }),

                    Select::make('customer_id')
                        ->label('Customer')
                        ->relationship('customer', 'display_name')
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required()
                        ->afterStateUpdated(function (?int $state, Set $set): void {
                            $quickbooksId = Customer::query()->find($state)?->quickbooks_id;
                            $set('customer_quickbooks_id', $quickbooksId);
                        }),

                    TextInput::make('customer_quickbooks_id')
                        ->label('Customer QuickBooks ID')
                        ->disabled()
                        ->dehydrated()
                        ->maxLength(255),

                    Select::make('inst_manager_user_id')
                        ->label('Installation Manager')
                        ->options(fn () => User::query()
                            ->whereHas('roles', fn ($q) => $q->where('name', 'Inst_Manager'))
                            ->orderBy('name')
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->preload()
                        ->required(),

                    Select::make('worker_user_id')
                        ->label('Worker')
                        ->options(fn () => User::query()
                            ->whereHas('roles', fn ($q) => $q->where('name', 'Worker'))
                            ->orderBy('name')
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->preload()
                        ->required(),

                    DateTimePicker::make('performed_at')
                        ->seconds(false),

                    Select::make('status')
                        ->options([
                            'draft' => 'Draft',
                            'scheduled' => 'Scheduled',
                            'in_progress' => 'In Progress',
                            'completed' => 'Done (completed)',
                            'canceled' => 'Canceled',
                        ])
                        ->default('draft')
                        ->required(),
                ]),

            Section::make('Steps')
                ->schema([
                    Repeater::make('steps')
                        ->relationship()
                        ->columns(2)
                        ->collapsible()
                        ->reorderable(false)
                        ->addable(false)
                        ->deletable(false)
                        ->defaultItems(0)
                        ->schema([
                            TextInput::make('title')
                                ->required()
                                ->disabled()
                                ->dehydrated(true),

                            TextInput::make('sort_order')
                                ->numeric()
                                ->disabled()
                                ->dehydrated(true),

                            Textarea::make('description')
                                ->rows(2)
                                ->columnSpanFull()
                                ->disabled()
                                ->dehydrated(true),

                            Toggle::make('is_required')
                                ->disabled()
                                ->dehydrated(true),

                            Toggle::make('is_done')
                                ->label('Done'),

                            DateTimePicker::make('done_at')
                                ->seconds(false)
                                ->disabled()
                                ->dehydrated(true),

                            // FileUpload::make('img')
                            //     ->image()
                            //     ->disk('public')
                            //     ->visibility('public')
                            //     ->required()
                            //     ->disabled()
                            //     ->dehydrated(true),

                            TextInput::make('img')
                                ->disabled()
                                ->dehydrated(true),

                            Textarea::make('notes')
                                ->rows(3)
                                ->columnSpanFull(),
                        ]),
                ])
                ->collapsed()
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('customer.display_name')->label('Customer')->searchable(),
                Tables\Columns\TextColumn::make('equipment.model')->label('Equipment')->searchable(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('instManager.name')->label('Inst. Manager'),
                Tables\Columns\TextColumn::make('worker.name')->label('Worker'),
                Tables\Columns\TextColumn::make('created_at')->dateTime('m/d/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('performed_at')->dateTime('m/d/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'scheduled' => 'Scheduled',
                        'in_progress' => 'In Progress',
                        'completed' => 'Done (completed)',
                        'canceled' => 'Canceled',
                    ]),
                Filter::make('created_at')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
                Filter::make('performed_at')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('performed_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('performed_at', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->modalWidth(MaxWidth::SevenExtraLarge),
                Tables\Actions\EditAction::make()->modalWidth(MaxWidth::SevenExtraLarge),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInstallations::route('/'),
            'create' => Pages\CreateInstallation::route('/create'),
            'view' => Pages\ViewInstallation::route('/{record}'),
            'edit' => Pages\EditInstallation::route('/{record}/edit'),
        ];
    }
}
