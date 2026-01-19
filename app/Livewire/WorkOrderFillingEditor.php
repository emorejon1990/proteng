<?php

namespace App\Livewire;

use App\Models\Asset;
use Livewire\Component;
use App\Models\Products;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\ProductsController;

class WorkOrderFillingEditor extends Component
{
    public WorkOrder $workOrder;
    public $productIds = [];
    public $currentIndex = 0;
    public $currentProduct;

    public $checkTest = false;

    public bool $filled = false; // Agrega esta propiedad

    public $currentProductData = [
        'weight' => null,
    ];

    protected $listeners = [
        'pesoManualCambiado' => 'validatePeso'
    ];

    public $asset;
    public $min;
    public $max;

    public function mount(WorkOrder $workOrder)
    {
        $this->asset = Asset::find($workOrder->asset_id);
        $this->min = $this->asset->weight - $this->asset->weight_tolerance;
        $this->max = $this->asset->weight + $this->asset->weight_tolerance;
        // dd($this->min);

        $this->workOrder = $workOrder;
        // dump($workOrder->id);
        $this->productIds = $workOrder->products()
            ->where(function ($query) {
                $query->where('filled', false)
                  ->orWhereNull('filled');
            })
        ->pluck('id')
        ->toArray();
        // dump($this->productIds);
        $this->loadCurrentProduct();
    }

    public function loadCurrentProduct()
    {
        if (!isset($this->productIds[$this->currentIndex])) {
            $this->currentProduct = null;
            return;
        }

        $this->currentProduct = Products::find($this->productIds[$this->currentIndex])->fresh();
        $this->filled = (bool) $this->currentProduct?->filled;

        // Copiar datos del modelo al array editable
        $this->currentProductData['weight'] = $this->currentProduct->weight;
    }

    public function saveAndNext()
    {
        if (!$this->currentProduct) return;

        $serial = ProductsController::Serial();

        // Asigna valores obligatorios
        $this->currentProduct->fill_by = Auth::id();
        $this->currentProduct->fill_date = now();
        $this->currentProduct->filled = $this->filled;
        $this->currentProduct->weight = $this->currentProductData['weight'];
        $this->currentProduct->location_id = 2;
        $this->currentProduct->serial = $serial;


        $this->validate([
            'currentProduct.fill_by' => 'required|integer',
            'currentProduct.fill_date' => 'required|date',
            'currentProduct.filled' => 'required|boolean',
            'currentProductData.weight' => [ // ⚠️ validar el array, no el modelo
                'required',
                'numeric',
                function ($attribute, $value, $fail) {
                    if ($value < $this->min || $value > $this->max) {
                        $fail("Weight most be between {$this->min} and {$this->max}.");
                    }
                },
            ],
        ]);

        $this->currentProduct->save();
        $this->currentProduct->logHistory(
            process: "Filled",
            description: "Filled and Serial",
            location: 'Filled Area'
        );

        if ($this->currentIndex < count($this->productIds) - 1) {
            $this->currentIndex++;
            $this->loadCurrentProduct();
        } else {
            $this->workOrder->wc_id = 3;
            $this->workOrder->wc_changed_at = now();
            $this->workOrder->save();
            session()->flash('done', '¡Done!');
            $this->dispatch('reload-page');
        }
    }


    public function updatedCurrentProductDataWeight($value)
    {
        $this->validateOnly('currentProductData.weight', [
            'currentProductData.weight' => [
                'required',
                'numeric',
                function ($attribute, $value, $fail) {
                    if ($value < $this->min || $value > $this->max) {
                        $fail("Weight most be between {$this->min} and {$this->max}.");
                    }
                },
            ],
        ]);
    }

    public function validatePeso($value)
    {
        $this->currentProductData['weight'] = $value;

        $this->validateOnly('currentProductData.weight', [
            'currentProductData.weight' => [
                'required',
                'numeric',
                function ($attribute, $value, $fail) {
                    if ($value < $this->min || $value > $this->max) {
                        $fail("Weight most be between {$this->min} and {$this->max}.");
                    }
                },
            ],
        ]);
    }

    public function render()
    {
        // dump($this->currentProduct);
        return view('livewire.work-order-filling-editor', [
            'total' => count($this->productIds),
            'current' => $this->currentIndex + 1,
        ]);
    }
}
