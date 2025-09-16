<?php

namespace App\Livewire;

use App\Models\Asset;
use Livewire\Component;
use App\Models\Products;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Auth;

class WorkOrderQualityEditor extends Component
{
    public WorkOrder $workOrder;
    public $productIds = [];
    public $currentIndex = 0;
    public $currentProduct;

    public $checkTest = false;

    public bool $qualified = false; // Agrega esta propiedad

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
                $query->where('qualified', false)
                    ->where('location_id', '3')
                    ->orWhereNull('qualified');
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
        $this->qualified = (bool) $this->currentProduct?->qualified;

        // Copiar datos del modelo al array editable
        $this->currentProductData['weight'] = $this->currentProduct->f_weight;
    }

    public function saveAndNext()
    {
        if (!$this->currentProduct) return;

        // Asigna valores obligatorios
        $this->currentProduct->quality_by = Auth::id();
        $this->currentProduct->quality_date = now();
        $this->currentProduct->qualified = $this->qualified;
        $this->currentProduct->f_weight = $this->currentProductData['weight'];

        if ($this->currentProduct->qualified == true) {
            $this->currentProduct->location_id = 4;
            $this->currentProduct->work_order_id = null;
            $this->currentProduct->status_id = 6;
        }else {
            $this->currentProduct->location_id = 1;
        }

        $this->validate([
            'currentProduct.quality_by' => 'required|integer',
            'currentProduct.quality_date' => 'required|date',
            'currentProduct.qualified' => 'required|boolean',
            // 'currentProductData.weight' => [ // ⚠️ validar el array, no el modelo
            //     // 'required',
            //     'numeric',
            //     function ($attribute, $value, $fail) {
            //         if ($value < $this->min || $value > $this->max) {
            //             $fail("1Final weight most be between {$this->min} and {$this->max}.");
            //         }
            //     },
            // ],
        ]);

        $this->currentProduct->save();

        if ($this->currentIndex < count($this->productIds) - 1) {
            $this->currentIndex++;
            $this->loadCurrentProduct();
        } else {
            if ($this->workOrder->products()) {
                $this->workOrder->wc_id = 3;
            } else {
                $this->workOrder->wc_id = null;
                $this->workOrder->status_id = 6;
                $this->workOrder->wc_changed_at = now();
                if ($this->workOrder->for) {
                    $wop = WorkOrder::find($this->workOrder->for);
                    $wop->status_id = 3;
                    $wop->save();
                }
            }

            $this->workOrder->save();
            session()->flash('done', '¡Done!');
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
                        $fail("2Final weight most be between {$this->min} and {$this->max}.");
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
                        $fail("3Final weight most be between {$this->min} and {$this->max}.");
                    }
                },
            ],
        ]);
    }

    public function render()
    {
        return view('livewire.work-order-quality-editor', [
            'total' => count($this->productIds),
            'current' => $this->currentIndex + 1,
            'values' => "Final weight most be between {$this->min} and {$this->max}."
        ]);
    }
}
