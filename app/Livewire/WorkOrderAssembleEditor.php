<?php

namespace App\Livewire;

use App\Models\Products;
use App\Models\WorkOrder;
use App\Services\ProductionAssemblyService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

class WorkOrderAssembleEditor extends Component
{
    #[Locked]
    public WorkOrder $workOrder;

    #[Locked]
    public $productIds = [];

    #[Locked]
    public $currentIndex = 0;

    #[Locked]
    public $currentProduct;

    public $checkTest = false;

    public string $serialInput = '';

    public bool $assambled = false; // Agrega esta propiedad

    public $rules = [
        'currentProduct.assambly_by' => 'integer',
        'currentProduct.assambly_date' => 'date',
        'currentProduct.assambled' => 'required|boolean',
        'serialInput' => 'required_if:assambled,true|nullable|string|size:9|regex:/^[A-Z][0-9]{7}[A-Z]$/',
    ];

    public function mount(WorkOrder $workOrder)
    {
        $this->workOrder = $workOrder;
        app(ProductionAssemblyService::class)->checkOrder($workOrder, Auth::user());
        // dump($workOrder->id);
        $this->productIds = $workOrder->products()
            ->where(function ($query) {
                $query->where('assambled', false)
                    ->orWhereNull('assambled');
            })
            ->pluck('id')
            ->toArray();
        // dump($this->productIds);
        $this->loadCurrentProduct();
    }

    public function loadCurrentProduct()
    {
        app(ProductionAssemblyService::class)->authorize(Auth::user());
        $this->currentProduct = isset($this->productIds[$this->currentIndex])
            ? Products::find($this->productIds[$this->currentIndex]) : null;
        $this->assambled = (bool) $this->currentProduct?->assambled;
        $this->serialInput = $this->currentProduct?->serial ?? '';
        $this->resetValidation();
    }

    public function saveAndNext()
    {
        if (! $this->currentProduct) {
            return;
        }

        $this->currentProduct->assambled = $this->assambled;
        $this->currentProduct->assambly_by = Auth::id();
        $this->currentProduct->assambly_date = now();
        // $this->currentProduct->save();

        $this->serialInput = strtoupper(trim($this->serialInput));
        $this->validate();

        $this->currentProduct = app(ProductionAssemblyService::class)->assemble(
            $this->workOrder->id, $this->currentProduct->id, $this->serialInput, $this->assambled, Auth::user()
        );

        if ($this->currentIndex < count($this->productIds) - 1) {
            $this->currentIndex++;
            $this->loadCurrentProduct();
        } else {
            $this->workOrder = $this->workOrder->fresh();
            session()->flash('done', '¡Done!');
            $this->dispatch('reload-page');
        }
    }

    public function render()
    {
        app(ProductionAssemblyService::class)->authorize(Auth::user());

        return view('livewire.work-order-assemble-editor', [
            'total' => count($this->productIds),
            'current' => $this->currentIndex + 1,
        ]);
    }
}
