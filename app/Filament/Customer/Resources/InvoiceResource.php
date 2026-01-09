<?php

namespace App\Filament\Customer\Resources;

use Filament\Forms;
use Filament\Tables;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use App\Models\Invoice;
use App\Filament\Customer\Concerns\ScopesByCustomer;
use App\Filament\Customer\Resources\InvoiceResource\Pages;

class InvoiceResource extends Resource
{
    use ScopesByCustomer;

    protected static ?string $model = Invoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';
    protected static ?string $navigationLabel = 'Invoices';
    protected static ?string $navigationGroup = 'Billing';
    protected static ?int $navigationSort = 1;

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
        return $form->schema([
            Forms\Components\Section::make('Invoice')->schema([
                Forms\Components\TextInput::make('invoice_number')->disabled(),
                Forms\Components\DatePicker::make('issued_at')->disabled(),
                Forms\Components\DatePicker::make('due_at')->disabled(),
                Forms\Components\TextInput::make('status')->disabled(),
                Forms\Components\TextInput::make('total')->disabled(),
                Forms\Components\TextInput::make('balance')->disabled(),
            ]),
        ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('invoice_number')
                    ->label('Invoice #')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('issued_at')
                    ->label('Issued')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('due_at')
                    ->label('Due')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total')
                    ->money('USD') // cambia a tu moneda si aplica
                    ->sortable(),

                Tables\Columns\TextColumn::make('balance')
                    ->money('USD')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable(),
            ])
            ->defaultSort('issued_at', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('pdf')
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(fn (\App\Models\Invoice $record) => route('invoices.pdf', $record), shouldOpenInNewTab: true),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
            'view'  => Pages\ViewInvoice::route('/{record}'),
        ];
    }
}
