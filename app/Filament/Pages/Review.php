<?php

namespace App\Filament\Pages;

use App\Livewire\QrScannerModal;
use App\Models\WorkOrder;
use App\Services\ReviewInspectionService;
use App\Services\ReviewWorkOrderService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class Review extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationGroup = 'Shop Floor';

    protected static ?string $navigationLabel = 'Revisión';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.review';

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

    public function mount(): void
    {
        app(ReviewWorkOrderService::class)->authorize(Auth::user());
        try {
            $this->workOrders = app(ReviewInspectionService::class)->orders()->get();
            if ($this->workOrders->isNotEmpty()) {
                app(ReviewInspectionService::class)->checkOrder($this->workOrders->first(), Auth::user());
            }
        } catch (ValidationException $exception) {
            $this->workOrders = collect();
            $this->availabilityError = collect($exception->errors())->flatten()->first();
        }
    }

    public function openScanner(int $orderId): void
    {
        app(ReviewWorkOrderService::class)->authorize(Auth::user());
        $order = WorkOrder::find($orderId);
        if (! $order) {
            throw ValidationException::withMessages(['workOrder' => 'La orden ya no está disponible.']);
        }
        app(ReviewInspectionService::class)->checkOrder($order, Auth::user());
        $this->scanOrderId = $orderId;
        $this->resetValidation();
        $this->dispatch('open-qr-modal')->to(QrScannerModal::class);
    }

    public function setCode(string $code): void
    {
        app(ReviewWorkOrderService::class)->authorize(Auth::user());
        if ($this->scanOrderId === null) {
            throw ValidationException::withMessages(['serialInput' => 'Selecciona una orden antes de escanear con la cámara.']);
        }
        $association = app(ReviewInspectionService::class)->select($this->scanOrderId, $code, Auth::user());
        $this->dispatch('review-camera-scanned', orderId: $this->scanOrderId, code: $association->product->serial);
        $this->scanOrderId = null;
    }
}
