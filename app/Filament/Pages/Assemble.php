<?php

namespace App\Filament\Pages;

use App\Services\ProductionAssemblyService;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class Assemble extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static string $view = 'filament.pages.assemble';

    protected static ?string $navigationGroup = 'Shop Floor';

    protected static ?int $navigationSort = 1;

    public $workOrders;

    public ?string $availabilityError = null;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user && $user->hasRole(['Admin', 'Manager', 'Worker']);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getNavigationBadge(): ?string
    {

        try {
            return (string) app(ProductionAssemblyService::class)->orders()->count();
        } catch (ValidationException) {
            return null;
        }
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
        app(ProductionAssemblyService::class)->authorize(Auth::user());
        try {
            $this->workOrders = app(ProductionAssemblyService::class)->orders()->with('products')->get();
        } catch (ValidationException $exception) {
            $this->workOrders = collect();
            $this->availabilityError = collect($exception->errors())->flatten()->first();
        }
    }
}
