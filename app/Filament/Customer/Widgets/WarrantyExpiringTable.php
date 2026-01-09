<?php

namespace App\Filament\Customer\Widgets;

use Filament\Tables;
use App\Models\InstalledProduct;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use Filament\Widgets\TableWidget as BaseWidget;

class WarrantyExpiringTable extends BaseWidget
{
    protected static ?string $heading = 'Warranties expiring soon';

    protected function getTableQuery(): Builder
    {
        $customer = Auth::user()?->customer;
        abort_if(! $customer, 403);

        return InstalledProduct::query()
            ->where('customer_id', $customer->id)
            ->whereNotNull('warranty_expires_at')
            ->whereBetween('warranty_expires_at', [now(), now()->addDays(30)])
            ->orderBy('warranty_expires_at');
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('product.name')->label('Product')->sortable()->searchable(),
            Tables\Columns\TextColumn::make('serial_number')->label('Serial')->searchable(),
            Tables\Columns\TextColumn::make('warranty_expires_at')->label('Expires')->date()->sortable(),
            Tables\Columns\TextColumn::make('remaining')
                ->label('Remaining')
                ->getStateUsing(fn (InstalledProduct $record) => now()->diffForHumans($record->warranty_expires_at, true))
                ->badge(),
        ];
    }
}
