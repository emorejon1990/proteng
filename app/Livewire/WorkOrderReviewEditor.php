<?php

namespace App\Livewire;

use App\Models\Products;
use App\Models\WorkOrder;
use App\Services\ReviewInspectionService;
use App\Services\ReviewWorkOrderService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class WorkOrderReviewEditor extends Component
{
    #[Locked]
    public WorkOrder $workOrder;

    public string $serialInput = '';

    #[Locked]
    public ?int $selectedProductId = null;

    #[Locked]
    public string $selectedSerial = '';

    public array $decision = ['result' => 'repaired', 'diagnosis' => '', 'repair_description' => '', 'recycling_reason' => ''];

    #[Locked]
    public ?array $recyclingConfirmation = null;

    public string $notice = '';

    public function mount(WorkOrder $workOrder): void
    {
        $this->workOrder = $workOrder;
        app(ReviewInspectionService::class)->checkOrder($workOrder->fresh(), Auth::user());
    }

    #[Computed]
    public function currentProduct(): ?Products
    {
        return $this->selectedProductId === null ? null : Products::with('asset')->find($this->selectedProductId);
    }

    public function scanSerial(): void
    {
        $this->clearSelection();
        $association = app(ReviewInspectionService::class)->select($this->workOrder->id, $this->serialInput, Auth::user());
        $this->selectedProductId = $association->product_id;
        $this->selectedSerial = $association->product->serial;
        $this->serialInput = $this->selectedSerial;
        unset($this->currentProduct);
    }

    #[On('review-camera-scanned')]
    public function scanCamera(int $orderId, string $code): void
    {
        if ($orderId === $this->workOrder->id) {
            $this->serialInput = $code;
            $this->scanSerial();
        }
    }

    public function save(): void
    {
        $this->assertSelected();
        $service = app(ReviewInspectionService::class);
        $decision = $service->validateDecision($this->decision);
        if ($decision['result'] === 'recycled') {
            $service->select($this->workOrder->id, $this->selectedSerial, Auth::user());
            $this->recyclingConfirmation = $decision;

            return;
        }
        $this->saved($service->inspect($this->workOrder->id, $this->selectedProductId, $this->selectedSerial, $decision, Auth::user()));
    }

    public function confirmRecycling(): void
    {
        $this->assertSelected();
        $service = app(ReviewInspectionService::class);
        if ($this->recyclingConfirmation === null || $service->validateDecision($this->decision) !== $this->recyclingConfirmation) {
            throw ValidationException::withMessages(['confirmation' => 'Revisa los datos y solicita nuevamente la confirmación de reciclaje.']);
        }
        $this->saved($service->inspect($this->workOrder->id, $this->selectedProductId, $this->selectedSerial, $this->decision, Auth::user(), true));
    }

    public function cancelRecycling(): void
    {
        app(ReviewWorkOrderService::class)->authorize(Auth::user());
        $this->recyclingConfirmation = null;
        $this->resetValidation('confirmation');
    }

    public function updatedDecision(): void
    {
        $this->recyclingConfirmation = null;
        $this->resetValidation();
    }

    private function assertSelected(): void
    {
        app(ReviewWorkOrderService::class)->authorize(Auth::user());
        if ($this->selectedProductId === null || strtoupper(trim($this->serialInput)) !== $this->selectedSerial) {
            throw ValidationException::withMessages(['serialInput' => 'Escanea e identifica el tubo antes de guardar.']);
        }
    }

    private function clearSelection(): void
    {
        $this->selectedProductId = null;
        $this->selectedSerial = '';
        $this->decision = ['result' => 'repaired', 'diagnosis' => '', 'repair_description' => '', 'recycling_reason' => ''];
        $this->recyclingConfirmation = null;
        $this->notice = '';
        $this->resetValidation();
        unset($this->currentProduct);
    }

    private function saved(array $result): void
    {
        $this->clearSelection();
        $this->serialInput = '';
        $this->workOrder = $this->workOrder->fresh();
        $this->notice = $result['recycled'] ? 'Reciclaje registrado.' : 'Reparación registrada.';
        if ($result['completed']) {
            $this->notice .= $this->workOrder->wc_id === null ? ' Todos los tubos fueron reciclados. Orden finalizada.' : ' Revisión completa. Los reparados quedaron pendientes de llenado.';
        }
        $this->dispatch('review-saved', orderId: $this->workOrder->id);
    }

    public function render()
    {
        app(ReviewWorkOrderService::class)->authorize(Auth::user());
        $total = $this->workOrder->productAssociations()->where('purpose', 'review')->count();
        $pending = app(ReviewInspectionService::class)->pendingMembers($this->workOrder)->count();

        return view('livewire.work-order-review-editor', ['total' => $total, 'pendingCount' => $pending, 'reviewedCount' => $total - $pending]);
    }
}
