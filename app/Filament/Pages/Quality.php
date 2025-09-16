<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Models\WorkOrder;

class Quality extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static string $view = 'filament.pages.quality';

    protected static ?string $navigationGroup = 'Shop Floor';

    protected static ?int $navigationSort = 3;

    public $workOrders;

    public ?string $scannedCode = null;

    protected $listeners = ['code-scanned' => 'setCode'];

    public static function getNavigationBadge(): ?string
    {

        return WorkOrder::with('products')
            ->where('status_id', 3) // Asumiendo que '3' es "In Progress"
            ->where('wc_id', 4)->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Work Orders';
    }

    public function setCode($code)
    {
        $this->scannedCode = $code;
    }

    public function mount(): void
    {
        $this->workOrders = WorkOrder::with('products')
            ->where('status_id', 3) // Asumiendo que '3' es "In Progress"
            ->where('wc_id', 4)     // Quality
            ->get();
    }
}
