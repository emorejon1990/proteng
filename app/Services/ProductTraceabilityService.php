<?php

namespace App\Services;

use App\Models\ProductActiveAssignment;
use App\Models\ProductCycle;
use App\Models\Products;
use App\Models\WorkOrder;
use App\Models\WorkOrderProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Data primitives for future station services, not operator-facing workflow actions.
 * Callers must authorize the actor and validate station/type eligibility first.
 */
class ProductTraceabilityService
{
    public function recordProductionOrigin(Products $product, WorkOrder $order, int $productionTypeId): WorkOrderProduct
    {
        return DB::transaction(function () use ($product, $order, $productionTypeId) {
            $order = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $product = Products::whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($productionTypeId < 1 || (int) $order->type_id !== $productionTypeId
                || ($product->production_work_order_id !== null && (int) $product->production_work_order_id !== $order->id)
                || ($product->production_work_order_id === null && (int) $product->work_order_id !== $order->id)) {
                throw ValidationException::withMessages(['product' => 'No se puede verificar la orden original de producción.']);
            }
            if ($product->production_work_order_id === null) {
                $product->production_work_order_id = $order->id;
                $product->save();
            }

            return WorkOrderProduct::firstOrCreate([
                'product_id' => $product->id,
                'work_order_id' => $order->id,
                'purpose' => WorkOrderProduct::PURPOSE_PRODUCTION,
            ], ['state' => WorkOrderProduct::STATE_RECORDED]);
        });
    }

    public function claim(WorkOrderProduct $association, ?ProductCycle $cycle = null, ?int $actorId = null): ProductActiveAssignment
    {
        return DB::transaction(function () use ($association, $cycle, $actorId) {
            // Lock the product even when no assignment row exists yet.
            $association = $this->lockAssociation($association);
            $assignment = ProductActiveAssignment::whereKey($association->product_id)->lockForUpdate()->first();
            if ($assignment?->work_order_product_id !== null
                && (int) $assignment->work_order_product_id !== $association->id) {
                throw ValidationException::withMessages(['product' => 'El producto ya pertenece a otra orden operativa.']);
            }
            if ($cycle) {
                $cycle = ProductCycle::whereKey($cycle->id)->lockForUpdate()->firstOrFail();
                if ((int) $cycle->product_id !== (int) $association->product_id) {
                    throw ValidationException::withMessages(['product' => 'El ciclo no corresponde a este producto.']);
                }
            }
            if ($assignment?->product_cycle_id !== null
                && (int) $assignment->product_cycle_id !== $cycle?->id) {
                throw ValidationException::withMessages(['product' => 'El producto ya tiene otro ciclo operativo.']);
            }

            return ProductActiveAssignment::updateOrCreate(['product_id' => $association->product_id], [
                'work_order_product_id' => $association->id,
                'product_cycle_id' => $cycle?->id,
                'updated_by' => $actorId,
            ]);
        });
    }

    public function release(WorkOrderProduct $association): void
    {
        DB::transaction(function () use ($association) {
            $association = $this->lockAssociation($association);
            $assignment = ProductActiveAssignment::whereKey($association->product_id)->lockForUpdate()->first();
            if (! $assignment || $assignment->work_order_product_id === null) {
                return;
            }
            if ((int) $assignment->work_order_product_id !== $association->id) {
                throw ValidationException::withMessages(['product' => 'La asignación vigente pertenece a otra orden.']);
            }
            $assignment->update(['work_order_product_id' => null, 'product_cycle_id' => null, 'updated_by' => null]);
        });
    }

    private function lockAssociation(WorkOrderProduct $association): WorkOrderProduct
    {
        // Read authoritative identity before selecting lock targets; never trust mutable input attributes.
        $association = WorkOrderProduct::findOrFail($association->id);
        WorkOrder::whereKey($association->work_order_id)->lockForUpdate()->firstOrFail();
        Products::whereKey($association->product_id)->lockForUpdate()->firstOrFail();

        return WorkOrderProduct::whereKey($association->id)->lockForUpdate()->firstOrFail();
    }
}
