<?php

namespace App\Livewire;

use App\Models\Products;
use App\Models\WorkOrder;
use App\Services\ProductionQualityService;
use App\Services\RepairCycleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class WorkOrderQualityEditor extends Component
{
    #[Locked]
    public WorkOrder $workOrder;

    public string $serialInput = '';

    #[Locked]
    public ?int $selectedProductId = null;

    #[Locked]
    public string $selectedSerial = '';

    public array $currentProductData = ['weight' => null];

    #[Locked]
    public ?array $measurement = null;

    #[Locked]
    public bool $confirmingRejection = false;

    public string $notice = '';

    public function mount(WorkOrder $workOrder): void
    {
        $this->workOrder = $workOrder;
        app(ProductionQualityService::class)->checkOrder($workOrder->fresh(), Auth::user());
    }

    #[Computed]
    public function currentProduct(): ?Products
    {
        return $this->selectedProductId === null ? null : Products::with('asset')->find($this->selectedProductId);
    }

    public function scanSerial(): void
    {
        $this->clearSelection();
        $product = app(ProductionQualityService::class)->select($this->workOrder->id, $this->serialInput, Auth::user());
        $this->selectedProductId = $product->id;
        $this->selectedSerial = $product->serial;
        $this->serialInput = $product->serial;
        unset($this->currentProduct);
        $this->activateScale();
    }

    #[On('quality-camera-scanned')]
    public function scanCamera(int $orderId, string $code): void
    {
        if ($orderId !== $this->workOrder->id) {
            return;
        }
        $this->serialInput = $code;
        $this->scanSerial();
    }

    public function evaluateWeight(): void
    {
        $this->measurement = null;
        $this->confirmingRejection = false;
        $this->assertSelected();
        $this->measurement = app(ProductionQualityService::class)->measure(
            $this->workOrder->id, $this->selectedProductId, $this->selectedSerial,
            $this->currentProductData['weight'] ?? null, Auth::user()
        );
        $this->resetValidation();
        // Freeze automatic readings until the operator explicitly weighs again.
        $this->dispatch('quality-selection-cleared', orderId: $this->workOrder->id);
    }

    public function saveAndNext(): void
    {
        $this->assertSelected();
        $result = app(ProductionQualityService::class)->complete(
            $this->workOrder->id, $this->selectedProductId, $this->selectedSerial,
            $this->currentProductData['weight'] ?? null, Auth::user()
        );
        $this->saved($result);
    }

    public function requestReview(): void
    {
        $this->evaluateWeight();
        if ($this->measurement['approved']) {
            throw ValidationException::withMessages(['confirmation' => 'Este peso cumple la tolerancia. No corresponde enviarlo a revisión.']);
        }
        $this->confirmingRejection = true;
    }

    public function confirmReview(): void
    {
        $this->assertSelected();
        if (! $this->confirmingRejection || ! $this->measurement || $this->measurement['approved']) {
            throw ValidationException::withMessages(['confirmation' => 'Evalúa el peso y solicita confirmación antes de enviar a revisión.']);
        }
        $result = app(ProductionQualityService::class)->complete(
            $this->workOrder->id, $this->selectedProductId, $this->selectedSerial,
            $this->currentProductData['weight'] ?? null, Auth::user(), $this->measurement
        );
        $this->saved($result);
    }

    public function cancelReview(): void
    {
        app(ProductionQualityService::class)->authorize(Auth::user());
        $this->confirmingRejection = false;
        $this->resetValidation('confirmation');
    }

    public function weighAgain(): void
    {
        $this->assertSelected();
        $this->currentProductData = ['weight' => null];
        $this->measurement = null;
        $this->confirmingRejection = false;
        $this->resetValidation();
        $this->activateScale();
        $this->dispatch('quality-weigh-again', orderId: $this->workOrder->id);
    }

    public function updatedCurrentProductDataWeight(): void
    {
        $this->measurement = null;
        $this->confirmingRejection = false;
        $this->resetValidation();
    }

    public function receiveScaleWeight(mixed $weight, int $productId): void
    {
        app(ProductionQualityService::class)->authorize(Auth::user());
        if ($this->selectedProductId !== $productId || $this->measurement !== null || $this->confirmingRejection) {
            return;
        }
        $this->assertSelected();
        app(ProductionQualityService::class)->measure($this->workOrder->id, $productId, $this->selectedSerial, $weight, Auth::user());
        $this->currentProductData['weight'] = $weight;
        $this->resetValidation('currentProductData.weight');
    }

    public function activateScale(): void
    {
        $this->assertSelected();
        app(ProductionQualityService::class)->select($this->workOrder->id, $this->selectedSerial, Auth::user());
        if ($this->measurement === null && ! $this->confirmingRejection) {
            $this->dispatch('quality-product-selected', orderId: $this->workOrder->id, productId: $this->selectedProductId);
        }
    }

    private function assertSelected(): void
    {
        app(ProductionQualityService::class)->authorize(Auth::user());
        if ($this->selectedProductId === null || strtoupper(trim($this->serialInput)) !== $this->selectedSerial) {
            throw ValidationException::withMessages(['serialInput' => 'Escanea e identifica el tubo antes de continuar.']);
        }
    }

    private function clearSelection(): void
    {
        $this->selectedProductId = null;
        $this->selectedSerial = '';
        $this->currentProductData = ['weight' => null];
        $this->measurement = null;
        $this->confirmingRejection = false;
        $this->notice = '';
        $this->resetValidation();
        unset($this->currentProduct);
        $this->dispatch('quality-selection-cleared', orderId: $this->workOrder->id);
    }

    private function saved(array $result): void
    {
        $this->clearSelection();
        $this->serialInput = '';
        $this->workOrder = $this->workOrder->fresh();
        $this->notice = $result['measurement']['approved'] ? 'Calidad aprobada. El tubo pasó a Stock.' : 'Rechazo confirmado. El tubo se incorporó a la orden '.$result['review_order']->name.'.';
        if ($result['completed']) {
            $this->notice .= ' Calidad de la orden completada.';
        }
        $this->dispatch('quality-saved', orderId: $this->workOrder->id);
    }

    public function render()
    {
        $service = app(ProductionQualityService::class);
        $service->authorize(Auth::user());
        $total = app(RepairCycleService::class)->isReview($this->workOrder)
            ? app(RepairCycleService::class)->members($this->workOrder)->count()
            : Products::where(function ($query) {
                $query->where('work_order_id', $this->workOrder->id)->orWhere('production_work_order_id', $this->workOrder->id);
            })->count();
        $pending = $service->pendingProducts($this->workOrder)->count();

        return view('livewire.work-order-quality-editor', ['total' => $total, 'pendingCount' => $pending, 'completedCount' => $total - $pending]);
    }
}
