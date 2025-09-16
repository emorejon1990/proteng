<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Products;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Auth;

class WorkOrderAssembleEditor extends Component
{
    public WorkOrder $workOrder;
    public $productIds = [];
    public $currentIndex = 0;
    public $currentProduct;

    public $checkTest = false;

    public bool $assambled = false; // Agrega esta propiedad

    public $rules = [
        'currentProduct.assambly_by' => 'integer',
        'currentProduct.assambly_date' => 'date',
        'currentProduct.assambled' => 'required|boolean',
    ];

    public function mount(WorkOrder $workOrder)
    {
        $this->workOrder = $workOrder;
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
        $this->currentProduct = Products::find($this->productIds[$this->currentIndex]);
        $this->assambled = (bool) $this->currentProduct?->assambled;
    }

    public function saveAndNext()
    {
        if (!$this->currentProduct) return;

        $this->currentProduct->assambled = $this->assambled;
        $this->currentProduct->assambly_by = Auth::id();
        $this->currentProduct->assambly_date = now();
        // $this->currentProduct->save();

        $this->validate();

        $this->currentProduct->save();

        if ($this->currentIndex < count($this->productIds) - 1) {
            $this->currentIndex++;
            $this->loadCurrentProduct();
        } else {
            $this->workOrder->wc_id = 2;
            $this->workOrder->save();
            session()->flash('done', '¡Done!');
        }
    }

    public function render()
    {
        return view('livewire.work-order-assemble-editor', [
            'total' => count($this->productIds),
            'current' => $this->currentIndex + 1,
        ]);
    }
}
