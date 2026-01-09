<?php

namespace App\Filament\Customer\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\InstalledProduct;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use App\Filament\Customer\Concerns\ScopesByCustomer;
use App\Filament\Customer\Resources\InstalledProductResource\Pages;

class InstalledProductResource extends Resource
{
    use ScopesByCustomer;

    protected static ?string $model = InstalledProduct::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';
    protected static ?string $navigationLabel = 'Installed Products';
    protected static ?string $navigationGroup = 'My Products';
    protected static ?int $navigationSort = 1;

    // ✅ Customer NO crea/edita/elimina
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Forms\Form $form): Forms\Form
    {
        // Aunque sea readonly, Filament necesita form para View page (si lo usas)
        return $form->schema([
            Forms\Components\Section::make('Product Information')->schema([
                Forms\Components\TextInput::make('serial_number')
                    ->label('Serial Number')
                    ->disabled(),

                Forms\Components\DatePicker::make('installed_at')
                    ->label('Installed At')
                    ->disabled(),

                Forms\Components\TextInput::make('warranty_months')
                    ->label('Warranty (months)')
                    ->numeric()
                    ->disabled(),

                Forms\Components\DatePicker::make('warranty_expires_at')
                    ->label('Warranty Expires At')
                    ->disabled(),
            ]),
        ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('serial_number')
                    ->label('Serial')
                    ->searchable(),

                TextColumn::make('installed_at')
                    ->label('Installed')
                    ->date()
                    ->sortable(),

                TextColumn::make('warranty_expires_at')
                    ->label('Warranty Expires')
                    ->date()
                    ->sortable(),

                TextColumn::make('warranty_remaining')
                    ->label('Remaining')
                    ->state(fn ($record) => $record->warrantyRemainingHuman())
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ?? '—')
                    ->color(fn ($record) => match ($record->warrantyStatus()) {
                        'active' => 'success',
                        'expiring' => 'warning',
                        'expired' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('warranty_expires_at', 'asc')
            ->actions([
                //
            ])
            ->bulkActions([]); // ✅ customer no bulk actions
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInstalledProducts::route('/'),
            'view'  => Pages\ViewInstalledProduct::route('/{record}'),
        ];
    }
}
