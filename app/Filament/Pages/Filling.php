<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Models\WorkOrder;
use Filament\Actions\Action;

class Filling extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static string $view = 'filament.pages.filling';

    protected static ?string $navigationGroup = 'Shop Floor';

    protected static ?int $navigationSort = 2;

    // protected function getHeaderActions(): array
    // {
    //     return [
    //         Action::make('edit')
    //             ->button()
    //             ->color('info'),
    //         Action::make('delete')
    //             ->button()
    //             ->color('primary'),
    //     ];
    // }

    public $workOrders;

    public static function getNavigationBadge(): ?string
    {

        return WorkOrder::with('products')
            ->where('status_id', 3) // Asumiendo que '3' es "In Progress"
            ->where('wc_id', 2)->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Work Orders';
    }

    public function mount(): void
    {
        $this->workOrders = WorkOrder::with('products')
            ->where('status_id', 3) // Asumiendo que '3' es "In Progress"
            ->where('wc_id', 2)     // Filling
            ->get();
    }
}
