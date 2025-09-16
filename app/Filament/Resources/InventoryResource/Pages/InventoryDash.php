<?php

namespace App\Filament\Resources\InventoryResource\Pages;

use Filament\Resources\Pages\Page;
use App\Filament\Resources\InventoryResource;
use App\Filament\Resources\InventoryResource\Widgets\WaitingChart;
use App\Filament\Resources\InventoryResource\Widgets\ProductionChart;
use App\Filament\Resources\InventoryResource\Widgets\QualityChart;
use App\Filament\Resources\InventoryResource\Widgets\StockChart;

class InventoryDash extends Page
{
    protected static string $resource = InventoryResource::class;

    protected static string $view = 'filament.resources.inventory-resource.pages.inventory-dash';

    public function getHeaderWidgetsColumns(): int | array
    {
        return 2;
    }

    public function getHeaderWidgets(): array
    {
        return [
            ProductionChart::class,
            WaitingChart::class,
            QualityChart::class,
            StockChart::class,
        ];
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
