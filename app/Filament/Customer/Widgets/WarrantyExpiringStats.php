<?php

namespace App\Filament\Customer\Widgets;

use App\Models\InstalledProduct;
use Illuminate\Support\Facades\Auth;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class WarrantyExpiringStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $customer = Auth::user()?->customer;
        abort_if(! $customer, 403);

        $days = 30;

        $countExpiring = InstalledProduct::query()
            ->where('customer_id', $customer->id)
            ->whereNotNull('warranty_expires_at')
            ->whereBetween('warranty_expires_at', [now(), now()->addDays($days)])
            ->count();

        $countActive = InstalledProduct::query()
            ->where('customer_id', $customer->id)
            ->whereNotNull('warranty_expires_at')
            ->where('warranty_expires_at', '>=', now())
            ->count();

        return [
            Stat::make("Expiring in {$days} days", $countExpiring),
            Stat::make('Active Warranties', $countActive),
        ];
    }
}
