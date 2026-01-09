<?php

namespace App\Filament\Customer\Widgets;

use App\Models\Invoice;
use Illuminate\Support\Facades\Auth;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OpenInvoicesStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $customer = Auth::user()?->customer;
        abort_if(! $customer, 403);

        $openCount = Invoice::query()
            ->where('customer_id', $customer->id)
            ->where('balance', '>', 0)
            ->count();

        $openTotalBalance = Invoice::query()
            ->where('customer_id', $customer->id)
            ->where('balance', '>', 0)
            ->sum('balance');

        return [
            Stat::make('Open Invoices', $openCount),
            Stat::make('Open Balance', number_format((float) $openTotalBalance, 2)),
        ];
    }
}
