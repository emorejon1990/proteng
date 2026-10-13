<?php

namespace App\Filament\Pages;

use App\Services\ProductionFillingService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

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
            return (string) app(ProductionFillingService::class)->orders()->count();
        } catch (ValidationException $exception) {
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

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        try {
            $this->workOrders = app(ProductionFillingService::class)->orders()->with('asset')->get();
            if ($this->workOrders->isNotEmpty()) {
                app(ProductionFillingService::class)->checkOrder($this->workOrders->first(), Auth::user());
            }
        } catch (ValidationException $exception) {
            $this->workOrders = collect();
            $this->availabilityError = collect($exception->errors())->flatten()->first();
        }
    }
}
