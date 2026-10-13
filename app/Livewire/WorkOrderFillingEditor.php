<?php

namespace App\Livewire;

use App\Models\Products;
use App\Models\WorkOrder;
use App\Services\ProductionFillingService;
use App\Services\RepairCycleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class WorkOrderFillingEditor extends Component
{
    #[Locked]
    public WorkOrder $workOrder;

    public string $serialInput = '';

    #[Locked]
    public ?int $selectedProductId = null;

    #[Locked]
    public string $selectedSerial = '';

    public array $currentProductData = ['weight' => null];

    public string $notice = '';

    public function mount(WorkOrder $workOrder): void
    {
        $this->workOrder = $workOrder;
        app(ProductionFillingService::class)->checkOrder($workOrder->fresh(), Auth::user());
    }

    #[Computed]
    public function currentProduct(): ?Products
    {
        return $this->selectedProductId === null ? null : Products::with('asset')->find($this->selectedProductId);
    }

    public function scanSerial(): void
    {
        $this->resetSelection();
        $product = app(ProductionFillingService::class)->select($this->workOrder->id, $this->serialInput, Auth::user());
        $this->selectedProductId = $product->id;
        $this->selectedSerial = $product->serial;
        $this->serialInput = $product->serial;
        unset($this->currentProduct);
        $this->dispatch('filling-product-selected', orderId: $this->workOrder->id, productId: $product->id);
    }

    public function saveAndNext(): void
    {
        app(ProductionFillingService::class)->authorize(Auth::user());
        if ($this->selectedProductId === null || strtoupper(trim($this->serialInput)) !== $this->selectedSerial) {
            throw ValidationException::withMessages(['serialInput' => 'Escanea e identifica el tubo antes de guardar.']);
        }
        $result = app(ProductionFillingService::class)->fill(
            $this->workOrder->id, $this->selectedProductId, $this->selectedSerial,
            $this->currentProductData['weight'] ?? null, Auth::user()
        );
        $this->resetSelection();
        $this->serialInput = '';
        $this->notice = $result['completed'] ? 'Llenado completo. La orden pasó al área de espera por cinco días.' : 'Llenado guardado. Escanea el siguiente tubo.';
        $this->workOrder = $this->workOrder->fresh();
        $this->dispatch('filling-saved', orderId: $this->workOrder->id);
    }

    public function receiveScaleWeight(mixed $weight, int $productId): void
    {
        app(ProductionFillingService::class)->authorize(Auth::user());
        if ($this->selectedProductId !== $productId) {
            return;
        }
        $product = app(ProductionFillingService::class)->select($this->workOrder->id, $this->selectedSerial, Auth::user());
        $this->currentProductData['weight'] = $weight;
        app(ProductionFillingService::class)->validateWeight($product, $weight);
        $this->resetValidation('currentProductData.weight');
    }

    public function activateScale(): void
    {
        if ($this->selectedProductId !== null) {
            $product = app(ProductionFillingService::class)->select($this->workOrder->id, $this->selectedSerial, Auth::user());
            $this->dispatch('filling-product-selected', orderId: $this->workOrder->id, productId: $product->id);
        }
    }

    private function resetSelection(): void
    {
        $this->selectedProductId = null;
        $this->selectedSerial = '';
        $this->currentProductData = ['weight' => null];
        $this->notice = '';
        $this->resetValidation();
        unset($this->currentProduct);
        $this->dispatch('filling-selection-cleared', orderId: $this->workOrder->id);
    }

    public function render()
    {
        app(ProductionFillingService::class)->authorize(Auth::user());
        $service = app(ProductionFillingService::class);
        $total = app(RepairCycleService::class)->isReview($this->workOrder)
            ? app(RepairCycleService::class)->members($this->workOrder)->count()
            : $this->workOrder->products()->count();
        $filledCount = $total - $service->pendingProducts($this->workOrder)->count();

        return view('livewire.work-order-filling-editor', [
            'total' => $total, 'filledCount' => $filledCount, 'pendingCount' => $total - $filledCount,
        ]);
    }
}
