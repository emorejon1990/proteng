<?php

namespace App\Services;

use App\Models\ProductCycle;
use App\Models\ProductRepair;
use App\Models\Products;
use App\Models\WorkOrder;
use App\Models\WorkOrderProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class RepairCycleService
{
    public function __construct(private ProductionCatalog $catalog, private ProductTraceabilityService $traceability) {}

    public function isReview(WorkOrder $order): bool
    {
        return (int) $order->type_id === $this->catalog->id('review_type');
    }

    public function checkOrder(WorkOrder $order): void
    {
        $members = $order->productAssociations()->where('purpose', 'review');
        if ($order->review_receiving_open || ! $order->review_closed_at || ! $members->exists()
            || (clone $members)->whereDoesntHave('repairs', fn ($query) => $query
                ->whereIn('result', ['repaired', 'recycled'])->whereNotNull('reviewed_at'))->exists()) {
            $this->fail('La inspección de esta orden Review todavía no está completa.');
        }
    }

    // Includes historical members even after quality releases or reassigns them.
    public function members(WorkOrder $order): Builder
    {
        return Products::whereHas('orderAssociations', fn ($query) => $query
            ->where('work_order_id', $order->id)->where('purpose', 'review')
            ->whereHas('repairs', fn ($repair) => $repair->where('result', 'repaired')->whereNotNull('reviewed_at')));
    }

    public function pending(WorkOrder $order, string $stage): Builder
    {
        return $this->members($order)->whereDoesntHave('cycles', fn ($cycle) => $cycle
            ->where('source', ProductCycle::SOURCE_REPAIR)->whereNotNull('product_repair_id')
            ->whereHas('association', fn ($association) => $association->where('work_order_id', $order->id)->where('purpose', 'review'))
            ->where($stage === 'fill' ? 'fill_completed' : 'quality_completed', true));
    }

    public function context(WorkOrder $order, Products $product): array
    {
        $association = $order->productAssociations()->where('product_id', $product->id)->where('purpose', 'review')->first();
        $repair = $association?->repairs()->first();
        $cycle = $product->cycles()->reorder()->orderByDesc('cycle_number')->first();
        $assignment = $product->activeAssignment;
        if (! $association || $association->state !== WorkOrderProduct::STATE_ACTIVE
            || (int) $product->work_order_id !== $order->id || $product->qualified || ! $product->assambled
            || ! $repair || $repair->result !== ProductRepair::RESULT_REPAIRED || ! $repair->reviewed_at
            || (int) $repair->product_id !== $product->id || (int) $repair->rejected_cycle_id !== (int) $association->rejected_cycle_id
            || ! $cycle || (int) $assignment?->work_order_product_id !== $association->id
            || (int) $assignment?->product_cycle_id !== $cycle->id
            || ! $product->productionWorkOrder || (int) $product->productionWorkOrder->type_id !== $this->catalog->id('production_type')
            || (int) $product->status_id === $this->catalog->id('recycled_product_status')) {
            $this->fail('Este tubo no tiene una reparación vigente y una asignación válida para esta orden.');
        }
        $new = $repair->newCycle;
        $rejected = $association->rejectedCycle;
        if (! $rejected || ! $rejected->quality_completed || ! $rejected->completed_at
            || $rejected->quality_result !== ProductCycle::RESULT_REJECTED) {
            $this->fail('No se puede verificar el rechazo que originó esta reparación.');
        }
        if ($new) {
            if ($new->id !== $cycle->id || (int) $cycle->work_order_product_id !== $association->id
                || $cycle->source !== ProductCycle::SOURCE_REPAIR || (int) $cycle->product_id !== $product->id) {
                $this->fail('El nuevo ciclo de este tubo requiere revisión del administrador.');
            }
        } elseif ($cycle->id !== (int) $association->rejected_cycle_id || ! $cycle->quality_completed
            || ! $cycle->completed_at || $cycle->quality_result !== ProductCycle::RESULT_REJECTED) {
            $this->fail('No se puede verificar el ciclo rechazado de esta reparación.');
        }

        return [$association, $repair, $new];
    }

    public function checkFilling(WorkOrder $order, Products $product): void
    {
        [, , $cycle] = $this->context($order, $product);
        if ($cycle?->fill_completed || $cycle?->quality_completed || $cycle?->completed_at
            || (int) $product->location_id !== $this->catalog->id('review_location')) {
            $this->fail('Este tubo ya completó el nuevo llenado o no está disponible en Review.');
        }
    }

    public function prepare(WorkOrder $order, Products $product, int $actorId): array
    {
        [$association, $repair, $cycle] = $this->context($order, $product);
        if (! $cycle) {
            $cycle = ProductCycle::create([
                'product_id' => $product->id, 'work_order_product_id' => $association->id,
                'product_repair_id' => $repair->id,
                'cycle_number' => $product->cycles()->max('cycle_number') + 1,
                'source' => ProductCycle::SOURCE_REPAIR, 'asset_id' => $product->asset_id,
                'fill_completed' => false, 'quality_completed' => false,
                'legacy_snapshot' => $product->getRawOriginal(),
            ]);
            // Replace the operational pointer, retaining the completed rejection.
            $this->traceability->release($association);
            $this->traceability->claim($association, $cycle, $actorId);
        }

        return [$association, $cycle];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['serialInput' => $message]);
    }
}
