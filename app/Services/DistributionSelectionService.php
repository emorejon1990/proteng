<?php

namespace App\Services;

use App\Http\Controllers\WorkOrderController;
use App\Models\ProductCycle;
use App\Models\Products;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DistributionSelectionService
{
    public function __construct(private ProductionCatalog $catalog, private ProductTraceabilityService $traceability) {}

    public function authorize(?User $actor): void
    {
        abort_unless($actor?->hasRole(['Admin', 'Manager', 'Worker']), 403);
    }

    public function orders(): Builder
    {
        return WorkOrder::where('type_id', $this->catalog->id('distribution_type'))
            ->where('status_id', $this->catalog->id('in_process_status'));
    }

    public function checkOrder(WorkOrder $order, ?User $actor): void
    {
        $this->authorize($actor);
        if (! Schema::hasColumn('work_order_products', 'distribution_serial') || ! Schema::hasTable('product_active_assignments')) {
            $this->fail('workOrder', 'La distribución requiere preparación del administrador.');
        }
        if (! $this->orders()->whereKey($order->id)->exists()) {
            $this->fail('workOrder', 'La orden no está habilitada para preparar distribución.');
        }
    }

    public function requirements(WorkOrder $order, bool $lock = false): array
    {
        $query = DB::table('wo_ass')->where('work_order_id', $order->id)->orderBy('asset_id');
        $rows = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($rows->isEmpty() || $rows->pluck('asset_id')->duplicates()->isNotEmpty()
            || $rows->contains(fn ($row) => ! is_numeric($row->quantity) || $row->quantity <= 0 || (int) $row->quantity != $row->quantity)) {
            $this->fail('workOrder', 'Los modelos y cantidades de esta orden requieren revisión administrativa.');
        }

        return $rows->mapWithKeys(fn ($row) => [(int) $row->asset_id => (int) $row->quantity])->all();
    }

    public function reservations(WorkOrder $order): Builder
    {
        return WorkOrderProduct::where('work_order_id', $order->id)->where('purpose', 'distribution')->where('state', 'reserved');
    }

    public function available(): Builder
    {
        return $this->stock()->whereDoesntHave('activeAssignment', fn ($query) => $query->whereNotNull('work_order_product_id')->orWhereNotNull('product_cycle_id'))
            ->whereDoesntHave('orderAssociations', fn ($query) => $query->whereIn('state', ['active', 'reserved']));
    }

    private function stock(): Builder
    {
        return Products::where('location_id', $this->catalog->id('stock_location'))
            ->whereNotNull('serial')->where('serial', '!=', '')
            ->whereRaw("TRIM(serial) <> ''")
            ->whereNotExists(fn ($duplicate) => $duplicate->selectRaw('1')->from('products as duplicate_serial')
                ->whereColumn('duplicate_serial.serial', 'products.serial')->whereColumn('duplicate_serial.id', '!=', 'products.id'))
            ->where('status_id', $this->catalog->id('ready_product_status'))
            ->whereNull('work_order_id')->where('assambled', true)->where('filled', true)->where('qualified', true)
            ->where(function ($query) {
                $query->whereHas('cycles', fn ($cycle) => $cycle->where('quality_completed', true)
                    ->where('quality_result', ProductCycle::RESULT_APPROVED)->whereNotNull('completed_at')
                    ->whereNotExists(fn ($later) => $later->selectRaw('1')->from('product_cycles as later')
                        ->whereColumn('later.product_id', 'product_cycles.product_id')
                        ->whereColumn('later.cycle_number', '>', 'product_cycles.cycle_number')))
                    ->orWhere(fn ($legacy) => $legacy->whereDoesntHave('cycles')->whereNotNull('quality_date')->where('f_weight', '>', 0));
            });
    }

    private function lockedOrder(int $orderId, ?User $actor): WorkOrder
    {
        $this->authorize($actor);
        // Serialize all distribution reservations, releases, confirmations and shortages.
        DB::table('wo_type')->where('id', $this->catalog->id('distribution_type'))->lockForUpdate()->first();
        $order = WorkOrder::whereKey($orderId)->lockForUpdate()->first();
        if (! $order) {
            $this->fail('workOrder', 'La orden ya no está disponible.');
        }
        $this->checkOrder($order, $actor);

        return $order;
    }

    public function reserve(int $orderId, int $assetId, string $serial, ?User $actor): WorkOrderProduct
    {
        return DB::transaction(function () use ($orderId, $assetId, $serial, $actor) {
            $order = $this->lockedOrder($orderId, $actor);
            $required = $this->requirements($order, true);
            if (! isset($required[$assetId])) {
                $this->fail('assetId', 'Selecciona un modelo solicitado por esta orden.');
            }
            $serial = strtoupper(trim($serial));
            if ($serial === '' || strlen($serial) > 255) {
                $this->fail('serialInput', 'Escanea un serial válido.');
            }
            $matches = Products::where('serial', $serial)->orderBy('id')->lockForUpdate()->limit(2)->get();
            if ($matches->count() !== 1) {
                $this->fail('serialInput', 'Serial no encontrado o duplicado.');
            }
            $product = $matches->first();
            if ((int) $product->asset_id !== $assetId) {
                $this->fail('serialInput', 'El tubo no corresponde al modelo seleccionado.');
            }
            if ($this->reservations($order)->where('product_id', $product->id)->exists()) {
                $this->fail('serialInput', 'Este tubo ya está escaneado en la distribución.');
            }
            if (! $this->available()->whereKey($product->id)->exists()) {
                $this->fail('serialInput', 'El tubo no está disponible en Stock o pertenece a otra orden o reserva.');
            }
            if ($this->reservations($order)->whereHas('product', fn ($query) => $query->where('asset_id', $assetId))->count() >= $required[$assetId]) {
                $this->fail('serialInput', 'La cantidad solicitada de este modelo ya está completa.');
            }
            $association = WorkOrderProduct::firstOrNew(['work_order_id' => $order->id, 'product_id' => $product->id, 'purpose' => 'distribution']);
            if ($association->exists && $association->state !== WorkOrderProduct::STATE_RELEASED) {
                $this->fail('serialInput', 'La asociación existente no permite reservar este tubo.');
            }
            $cycle = $product->cycles()->reorder()->orderByDesc('cycle_number')->first();
            $association->fill(['state' => 'reserved', 'associated_by' => $actor->id, 'associated_at' => now(), 'closed_at' => null,
                'distribution_serial' => $product->serial, 'distribution_asset_id' => $product->asset_id, 'distribution_cycle_id' => $cycle?->id])->save();
            $this->traceability->claim($association, $cycle, $actor->id);
            $this->audit($association, $actor, 'Distribution reserved');

            return $association;
        });
    }

    public function remove(int $orderId, int $associationId, ?User $actor): void
    {
        DB::transaction(function () use ($orderId, $associationId, $actor) {
            $order = $this->lockedOrder($orderId, $actor);
            $association = $this->reservations($order)->whereKey($associationId)->first();
            if (! $association) {
                $this->fail('selection', 'Esta selección ya no pertenece a la distribución actual.');
            }
            Products::whereKey($association->product_id)->lockForUpdate()->firstOrFail();
            $this->traceability->release($association);
            $association->update(['state' => 'released', 'closed_at' => now()]);
            $this->audit($association, $actor, 'Distribution reservation released');
        });
    }

    public function confirm(int $orderId, ?User $actor): WorkOrder
    {
        return DB::transaction(function () use ($orderId, $actor) {
            $order = $this->lockedOrder($orderId, $actor);
            $required = $this->requirements($order, true);
            $selections = $this->reservations($order)->orderBy('product_id')->get();
            $counts = [];
            foreach ($selections as $association) {
                $product = Products::whereKey($association->product_id)->lockForUpdate()->firstOrFail();
                $assignment = $product->activeAssignment;
                $cycle = $product->cycles()->reorder()->orderByDesc('cycle_number')->first();
                if (! $this->stock()->whereKey($product->id)->exists()
                    || $product->serial !== $association->distribution_serial
                    || (int) $product->asset_id !== (int) $association->distribution_asset_id
                    || ($association->distribution_cycle_id === null ? $cycle !== null : (int) $association->distribution_cycle_id !== $cycle?->id)
                    || (int) $assignment?->work_order_product_id !== $association->id
                    || ($assignment->product_cycle_id === null ? $cycle !== null : (int) $assignment->product_cycle_id !== $cycle?->id)
                    || $product->orderAssociations()->whereKeyNot($association->id)->whereIn('state', ['active', 'reserved'])->exists()) {
                    $this->fail('selection', 'Un tubo seleccionado dejó de estar disponible. Retíralo y escanea uno válido.');
                }
                $counts[(int) $product->asset_id] = ($counts[(int) $product->asset_id] ?? 0) + 1;
            }
            ksort($required);
            ksort($counts);
            if ($counts !== $required) {
                $this->fail('selection', 'Identifica exactamente todas las unidades solicitadas antes de confirmar.');
            }
            $location = $this->catalog->id('distribution_location');
            foreach ($selections as $association) {
                $association->product->update(['location_id' => $location, 'work_order_id' => $order->id]);
                $association->update(['state' => 'completed', 'closed_at' => now()]);
                $this->traceability->release($association);
                $this->audit($association, $actor, 'Distribution confirmed');
            }
            $order->update(['status_id' => $this->catalog->id('ready_order_status')]);

            return $order->fresh();
        });
    }

    public function createShortage(int $orderId, int $assetId, ?User $actor): WorkOrder
    {
        return DB::transaction(function () use ($orderId, $assetId, $actor) {
            $order = $this->lockedOrder($orderId, $actor);
            $required = $this->requirements($order, true);
            if (! isset($required[$assetId])) {
                $this->fail('assetId', 'Este modelo no está solicitado.');
            }
            $production = $this->catalog->id('production_type');
            if (WorkOrder::where('type_id', $production)->where('asset_id', $assetId)->where('for', $order->id)->exists()) {
                $this->fail('workOrder', 'Ya existe una orden de producción enlazada para este modelo.');
            }
            $selected = $this->reservations($order)->whereHas('product', fn ($query) => $query->where('asset_id', $assetId))->count();
            $missing = $required[$assetId] - $selected - $this->available()->where('asset_id', $assetId)->count();
            if ($missing <= 0) {
                $this->fail('workOrder', 'No hay faltantes para este modelo.');
            }
            $productionOrder = new WorkOrder;
            $productionOrder->forceFill(['name' => WorkOrderController::WOname('WO'), 'date' => now()->addDay()->toDateString(),
                'type_id' => $production, 'status_id' => $this->catalog->id('new_order_status'),
                'asset_id' => $assetId, 'quant' => $missing, 'for' => $order->id])->save();

            return $productionOrder;
        });
    }

    private function audit(WorkOrderProduct $association, User $actor, string $process): void
    {
        $product = $association->product->fresh();
        $serial = $association->distribution_serial;
        $product->history()->create(['process' => $process, 'description' => $process.'. Serial: '.$serial,
            'date' => now(), 'user_id' => $actor->id, 'location' => $process === 'Distribution confirmed' ? 'Distribution' : 'Stock',
            'work_order_id' => $association->work_order_id, 'work_order_product_id' => $association->id,
            'product_cycle_id' => $association->distribution_cycle_id, 'metadata' => ['serial' => $serial, 'asset_id' => (int) $association->distribution_asset_id]]);
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
