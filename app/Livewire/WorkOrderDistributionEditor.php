<?php

namespace App\Livewire;

use App\Http\Controllers\WorkOrderController;
use App\Models\Asset;
use Livewire\Component;
use App\Models\Products;
use App\Models\WorkOrder;
use Carbon\Carbon;

class WorkOrderDistributionEditor extends Component
{
    public WorkOrder $workOrder;
    public $products;
    public $checkedProducts = [];
    public Products $prod;
    public $stillalive = false;

    public function mount(WorkOrder $workOrder)
    {
        $this->workOrder = $workOrder;
        $this->refreshProducts($workOrder);

        // // Obtén la relación y conviértela a array para que Livewire pueda manejarla sin perder datos
        // $this->products = $workOrder->distributions()->withPivot('quantity')->get()->map(function ($product) {
        //     $prod = Products::where('asset_id',$product->id)->where('location_id','4')->get();
        //     return [
        //         'id' => $product->id,
        //         'name' => $product->name,
        //         'quantity' => $product->pivot->quantity,
        //         'dispo' => count($prod)
        //     ];
        // });

        // Inicializa los checkboxes
        foreach ($this->products as $product) {
            $this->checkedProducts[$product['id']] = false;
        }

    }

    private function refreshProducts($workOrder)
    {
        // Obtén la relación y conviértela a array para que Livewire pueda manejarla sin perder datos
        $this->products = $workOrder->distributions()->withPivot('quantity')->get()->map(function ($product) {
            $prod = Products::where('asset_id',$product->id)->where('location_id','4')->get();
            if (WorkOrder::where('asset_id', $product->id)->where('for', $this->workOrder->id)->exists())
            {
                $this->stillalive = false;
            }
            return [
                'id' => $product->id,
                'name' => $product->name,
                'quantity' => $product->pivot->quantity,
                'dispo' => count($prod),
                'hasWO'    => WorkOrder::where('asset_id', $product->id)
                    ->where('for', $this->workOrder->id)
                    ->exists(),
            ];

        });
        // dd($this->products);
        foreach ($this->products as $prod) {
            if($prod['hasWO'] == false)
            {
                $this->stillalive = true;
            }
        }

        if ($this->stillalive == false)
        {
            $this->workOrder->status_id = 5;
            $this->workOrder->save();
        }
    }

    public function getIsAllCheckedProperty()
    {
        return collect($this->checkedProducts)->every(fn ($value) => $value);
    }

    public function updatedCheckedProducts()
    {
        // dump($this->checkedProducts);
    }

    public function allChecked()
    {
        return collect($this->checkedProducts)->every(fn($v) => $v);
    }

    public function saveAndNext()
    {
        if (!$this->isAllChecked) {
            session()->flash('error', 'Debes seleccionar todos los productos.');
            return;
        }else {
            $this->workOrder->status_id = 6;
            $this->workOrder->save();
            session()->flash('done', 'Distribution Ready');
            return redirect(request()->header('Referer'));
        }

    }

    public function CreateWO($assetId, $neededQuantity)
    {
        // Buscar producto
        $asset = Asset::find($assetId);

        if (! $asset) {
            session()->flash('error', 'Producto no encontrado.');
            return;
        }

        // Evitar duplicados
        $exists = WorkOrder::where('asset_id', $asset->id)
            ->where('quant', $neededQuantity)
            ->where('for', $this->workOrder->id)
            ->exists();

        if ($exists) {
            session()->flash('error', "Ya existe una WorkOrder para este producto con la cantidad faltante.");
            return;
        }

        if (WorkOrder::find($this->workOrder->id, 'for')->for != null) {
            session()->flash('done', "The WorkOrder for {$asset->name} with {$neededQuantity} already exist.");
        }else {
            // Crear la WorkOrder
            WorkOrder::create([
                'name' => WorkOrderController::WOname('WO'),
                'date' => Carbon::now()->addDay()->format('Y-m-d'),
                'status_id' => '1',
                'type_id' => '1',
                'asset_id' => $asset->id,
                'quant' => $neededQuantity,
                'for' => $this->workOrder->id
            ]);
            // if ($this->stillalive == false) {
            //     $this->workOrder->status_id = 5;
            // }



            // 🔑 Refrescar productos para ocultar el botón inmediatamente
            $this->refreshProducts($this->workOrder);

            session()->flash('done', "The WorkOrder for {$asset->name} with {$neededQuantity} unities has been created.");

        }

    }

    public function render()
    {
        return view('livewire.work-order-distribution-editor');
    }
}
