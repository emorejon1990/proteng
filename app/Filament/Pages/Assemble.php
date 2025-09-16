<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Models\WorkOrder;
use Filament\Support\Enums\MaxWidth;

class Assemble extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static string $view = 'filament.pages.assemble';

    protected static ?string $navigationGroup = 'Shop Floor';

    protected static ?int $navigationSort = 1;

    public $workOrders;

    public static function getNavigationBadge(): ?string
    {

        return WorkOrder::with('products')
            ->where('status_id', 3) // Asumiendo que '3' es "In Progress"
            ->where('type_id', 1)->where('wc_id', 1)->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Work Orders';
    }

    // public function getMaxContentWidth(): MaxWidth
    // {
    //     return MaxWidth::Full;
    // }

    public function mount(): void
    {
        $this->workOrders = WorkOrder::with('products')
            ->where('status_id', 3) // Asumiendo que '3' es "In Progress"
            ->where('wc_id', 1)
            ->where('type_id', 1)     // filtro adicional
            ->get();
    }
}
