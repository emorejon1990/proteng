<?php

namespace App\Livewire;

use App\Models\WorkOrder;
use App\Models\WorkOrderProduct;
use App\Services\DistributionSelectionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class WorkOrderDistributionEditor extends Component
{
    #[Locked]
    public WorkOrder $workOrder;

    public ?int $assetId = null;

    public string $serialInput = '';

    public string $notice = '';

    public function mount(WorkOrder $workOrder): void
    {
        $this->workOrder = $workOrder;
        $service = app(DistributionSelectionService::class);
        $service->checkOrder($workOrder, Auth::user());
        $this->assetId = array_key_first($service->requirements($workOrder));
    }

    public function scanSerial(): void
    {
        $this->resetValidation();
        $association = app(DistributionSelectionService::class)->reserve($this->workOrder->id, $this->assetId ?? 0, $this->serialInput, Auth::user());
        $this->notice = 'Serial reservado: '.$association->product->serial;
        $this->serialInput = '';
        $this->dispatch('distribution-selection-changed', orderId: $this->workOrder->id);
    }

    #[On('distribution-camera-scanned')]
    public function scanCamera(int $orderId, string $code): void
    {
        if ($orderId === $this->workOrder->id) {
            $this->serialInput = $code;
            $this->scanSerial();
        }
    }

    public function removeSelection(int $associationId): void
    {
        app(DistributionSelectionService::class)->remove($this->workOrder->id, $associationId, Auth::user());
        $this->resetValidation();
        $this->notice = 'Selección retirada. El tubo conserva su historial.';
    }

    public function saveAndNext(): void
    {
        $this->workOrder = app(DistributionSelectionService::class)->confirm($this->workOrder->id, Auth::user());
        $this->resetValidation();
        $this->notice = 'Distribución confirmada con los seriales seleccionados.';
    }

    // Preserve the existing shortage action; quantity is always calculated by the server.
    public function CreateWO(int $assetId): void
    {
        $order = app(DistributionSelectionService::class)->createShortage($this->workOrder->id, $assetId, Auth::user());
        $this->notice = 'Orden '.$order->name.' creada por '.$order->quant.' unidades faltantes.';
    }

    public function render()
    {
        $service = app(DistributionSelectionService::class);
        $service->authorize(Auth::user());
        $requirements = $service->requirements($this->workOrder);
        $open = $service->orders()->whereKey($this->workOrder->id)->exists();
        $printInvoice = $this->workOrder->invoice()->first();
        if ($printInvoice && ! Gate::allows('printFromDistribution', [$printInvoice, $this->workOrder])) {
            $printInvoice = null;
        }
        $selections = ($open ? $service->reservations($this->workOrder)
            : WorkOrderProduct::where('work_order_id', $this->workOrder->id)->where('purpose', 'distribution')->where('state', 'completed'))
            ->with('product.asset', 'distributionAsset')->get();
        $models = $this->workOrder->distributions()->get()->map(function ($asset) use ($requirements, $selections, $service) {
            $selected = $selections->filter(fn ($selection) => (int) $selection->product->asset_id === $asset->id)->count();
            $available = $service->available()->where('asset_id', $asset->id)->count();

            return ['id' => $asset->id, 'name' => $asset->name, 'quantity' => $requirements[$asset->id],
                'selected' => $selected, 'pending' => max(0, $requirements[$asset->id] - $selected), 'available' => $available,
                'shortage' => max(0, $requirements[$asset->id] - $selected - $available),
                'hasWO' => WorkOrder::where('for', $this->workOrder->id)->where('asset_id', $asset->id)->exists()];
        });
        $complete = $models->isNotEmpty() && $models->every(fn ($model) => $model['selected'] === $model['quantity'])
            && $selections->count() === array_sum($requirements);

        return view('livewire.work-order-distribution-editor', compact('models', 'selections', 'complete', 'open', 'printInvoice'));
    }
}
