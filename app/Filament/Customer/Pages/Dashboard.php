<?php

namespace App\Filament\Customer\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use App\Filament\Customer\Widgets\OpenInvoicesStats;
use App\Filament\Customer\Widgets\WarrantyExpiringStats;
use App\Filament\Customer\Widgets\WarrantyExpiringTable;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    public function getWidgets(): array
    {
        return [
            OpenInvoicesStats::class,
            WarrantyExpiringStats::class,
            WarrantyExpiringTable::class, // opcional
        ];
    }
}
