<?php

namespace App\Filament\Pages;

use App\Livewire\QrScannerModal;
use App\Models\WorkOrder;
use App\Services\ProductionQualityService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class Quality extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static string $view = 'filament.pages.quality';

    protected static ?string $navigationGroup = 'Shop Floor';

    protected static ?int $navigationSort = 3;

    public $workOrders;

    public ?string $availabilityError = null;

    public ?string $scannedCode = null;

    #[Locked]
    public ?int $scanOrderId = null;

    protected $listeners = ['code-scanned' => 'setCode'];

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
            return (string) app(ProductionQualityService::class)->orders()->count();
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

    public function openScanner(int $orderId): void
    {
        $service = app(ProductionQualityService::class);
        $service->authorize(Auth::user());
        $order = WorkOrder::find($orderId);
        if (! $order) {
            throw ValidationException::withMessages(['workOrder' => 'La orden ya no está disponible.']);
        }
        $service->checkOrder($order, Auth::user());
        $this->scanOrderId = $orderId;
        $this->resetValidation();
        $this->dispatch('open-qr-modal')->to(QrScannerModal::class);
    }

    public function setCode(string $code): void
    {
        app(ProductionQualityService::class)->authorize(Auth::user());
        if ($this->scanOrderId === null) {
            throw ValidationException::withMessages(['serialInput' => 'Selecciona una orden antes de escanear con la cámara.']);
        }
        $product = app(ProductionQualityService::class)->select($this->scanOrderId, $code, Auth::user());
        $this->scannedCode = $product->serial;
        $this->dispatch('quality-camera-scanned', orderId: $this->scanOrderId, code: $product->serial);
        $this->scanOrderId = null;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        try {
            $this->workOrders = app(ProductionQualityService::class)->orders()->with('asset')->get();
            if ($this->workOrders->isNotEmpty()) {
                app(ProductionQualityService::class)->checkOrder($this->workOrders->first(), Auth::user());
            }
        } catch (ValidationException $exception) {
            $this->workOrders = collect();
            $this->availabilityError = collect($exception->errors())->flatten()->first();
        }
    }
}
