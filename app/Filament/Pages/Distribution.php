<?php

namespace App\Filament\Pages;

use App\Livewire\QrScannerModal;
use App\Models\WorkOrder;
use App\Services\DistributionSelectionService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class Distribution extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static string $view = 'filament.pages.distribution';

    protected static ?string $navigationGroup = 'Shop Floor';

    protected static ?int $navigationSort = 4;

    public $workOrders;

    public ?string $availabilityError = null;

    #[Locked]
    public ?int $scanOrderId = null;

    protected $listeners = ['code-scanned' => 'setCode'];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasRole(['Admin', 'Manager', 'Worker']);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }
        try {
            return (string) app(DistributionSelectionService::class)->orders()->count();
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

    public function mount(): void
    {
        app(DistributionSelectionService::class)->authorize(Auth::user());
        try {
            $this->workOrders = app(DistributionSelectionService::class)->orders()->get();
        } catch (ValidationException $exception) {
            $this->workOrders = collect();
            $this->availabilityError = collect($exception->errors())->flatten()->first();
        }
    }

    public function openScanner(int $orderId): void
    {
        $order = WorkOrder::findOrFail($orderId);
        app(DistributionSelectionService::class)->checkOrder($order, Auth::user());
        $this->scanOrderId = $orderId;
        $this->dispatch('open-qr-modal')->to(QrScannerModal::class);
    }

    public function setCode(string $code): void
    {
        $service = app(DistributionSelectionService::class);
        $service->authorize(Auth::user());
        if ($this->scanOrderId === null) {
            throw ValidationException::withMessages(['serialInput' => 'Selecciona una orden antes de usar la cámara.']);
        }
        $service->checkOrder(WorkOrder::findOrFail($this->scanOrderId), Auth::user());
        $this->dispatch('distribution-camera-scanned', orderId: $this->scanOrderId, code: $code);
        $this->scanOrderId = null;
    }
}
