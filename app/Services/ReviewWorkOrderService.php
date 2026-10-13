<?php

namespace App\Services;

use App\Models\ProductCycle;
use App\Models\Products;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ReviewWorkOrderService
{
    public function __construct(private ProductionCatalog $catalog, private ProductTraceabilityService $traceability) {}

    public function authorize(?User $actor, bool $administrative = false): void
    {
        abort_unless($actor?->hasRole($administrative ? ['Admin', 'Manager'] : ['Admin', 'Manager', 'Worker']), 403);
    }

    public function orders(): Builder
    {
        $this->checkSchema();

        return WorkOrder::where('type_id', $this->catalog->id('review_type'));
    }

    // Every Review mutation acquires this same existing row first, including when
    // no receiving order exists yet. This serializes capacity and order creation.
    public function lockCatalog(): int
    {
        $this->checkSchema();
        $type = $this->catalog->id('review_type');
        DB::table('wo_type')->where('id', $type)->lockForUpdate()->first();

        return $type;
    }

    public function enqueue(ProductCycle $cycle, ?User $actor): WorkOrderProduct
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($cycle, $actor) {
            $type = $this->lockCatalog();
            $cycle = ProductCycle::find($cycle->id);
            if (! $cycle || ! $cycle->association) {
                $this->fail('No se puede verificar la orden del ciclo rechazado.');
            }
            $origin = WorkOrder::whereKey($cycle->association->work_order_id)->lockForUpdate()->firstOrFail();
            $product = Products::whereKey($cycle->product_id)->lockForUpdate()->firstOrFail();
            $cycle = ProductCycle::whereKey($cycle->id)->lockForUpdate()->firstOrFail();
            if (! $cycle->quality_completed || $cycle->quality_result !== ProductCycle::RESULT_REJECTED || ! $cycle->completed_at
                || $product->cycles()->reorder()->orderByDesc('cycle_number')->value('id') !== $cycle->id) {
                $this->fail('Este ciclo no corresponde a un rechazo vigente confirmado.');
            }
            $existing = WorkOrderProduct::where('rejected_cycle_id', $cycle->id)->first();
            if ($existing) {
                return $existing;
            }
            $production = $cycle->association->purpose === WorkOrderProduct::PURPOSE_PRODUCTION
                && (int) $origin->type_id === $this->catalog->id('production_type')
                && (int) $product->production_work_order_id === $origin->id;
            $repair = $cycle->association->purpose === WorkOrderProduct::PURPOSE_REVIEW
                && (int) $origin->type_id === $type && $cycle->source === ProductCycle::SOURCE_REPAIR
                && $cycle->repair?->result === 'repaired' && $cycle->repair->reviewed_at
                && (int) $cycle->repair->work_order_product_id === $cycle->work_order_product_id
                && (int) $cycle->repair->product_id === $product->id
                && (int) $product->productionWorkOrder?->type_id === $this->catalog->id('production_type');
            if ((! $production && ! $repair)
                || (int) $product->work_order_id !== $origin->id || $product->qualified
                || (int) $product->location_id !== $this->catalog->id('quality_location')) {
                $this->fail('El producto no está disponible para incorporarlo a revisión.');
            }
            $assignment = $product->activeAssignment;
            if ($assignment?->work_order_product_id !== null
                && ((int) $assignment->work_order_product_id !== $cycle->work_order_product_id
                    || (int) $assignment->product_cycle_id !== $cycle->id)) {
                $this->fail('El producto ya pertenece a otra orden operativa.');
            }
            $new = $this->catalog->id('new_order_status');
            $review = null;
            foreach (WorkOrder::where('type_id', $type)->where('review_receiving_open', true)->orderBy('id')->lockForUpdate()->get() as $candidate) {
                if ((int) $candidate->status_id !== $new || $candidate->review_closed_at !== null) {
                    $this->fail('Hay una orden Review con recepción y estado inconsistentes. Contacta al administrador.');
                }
                $count = $candidate->productAssociations()->where('purpose', WorkOrderProduct::PURPOSE_REVIEW)->count();
                if ($count > WorkOrder::REVIEW_CAPACITY) {
                    $this->fail('Una orden Review excede la capacidad y requiere revisión administrativa.');
                }
                if ($count === WorkOrder::REVIEW_CAPACITY) {
                    $this->closeReceiving($candidate, $actor->id);

                    continue;
                }
                $review = $candidate;
                break;
            }
            if (! $review) {
                $review = new WorkOrder;
                $review->forceFill([
                    'name' => $this->nextName(), 'date' => now()->toDateString(), 'type_id' => $type,
                    'status_id' => $new, 'wc_id' => null, 'asset_id' => null, 'quant' => 0,
                    'review_receiving_open' => true,
                ])->save();
            }
            $association = WorkOrderProduct::create([
                'product_id' => $product->id, 'work_order_id' => $review->id, 'purpose' => WorkOrderProduct::PURPOSE_REVIEW,
                'state' => WorkOrderProduct::STATE_ACTIVE, 'rejected_cycle_id' => $cycle->id,
                'associated_by' => $actor->id, 'associated_at' => now(),
            ]);
            $this->traceability->release($cycle->association);
            $cycle->association->update(['state' => WorkOrderProduct::STATE_COMPLETED, 'closed_at' => now()]);
            // Each completed rejection stays on the order/cycle that produced it.
            $this->traceability->claim($association, $cycle, $actor->id);
            $product->update(['work_order_id' => $review->id]);
            $review->quant = $review->productAssociations()->where('purpose', WorkOrderProduct::PURPOSE_REVIEW)->count();
            $review->save();
            $this->audit($association, $actor, 'Review assigned', 'Producto incorporado a una orden de revisión.', $cycle);
            if ($review->quant === WorkOrder::REVIEW_CAPACITY) {
                $this->closeReceiving($review, $actor->id);
            }

            return $association;
        });
    }

    public function close(int $orderId, ?User $actor): WorkOrder
    {
        $this->authorize($actor, true);

        return DB::transaction(function () use ($orderId, $actor) {
            $order = $this->lockedOrder($orderId);
            if ((int) $order->status_id !== $this->catalog->id('new_order_status')) {
                $this->fail('Solo se puede cerrar una orden Review pendiente de aprobación.');
            }
            $count = $this->validateMembers($order);
            if (! $order->review_receiving_open) {
                return $order;
            }
            $order->quant = $count;
            $this->closeReceiving($order, $actor->id);
            foreach ($order->productAssociations()->where('purpose', WorkOrderProduct::PURPOSE_REVIEW)->get() as $association) {
                $this->audit($association, $actor, 'Review receiving closed', 'Recepción cerrada; orden pendiente de aprobación.', $association->rejectedCycle);
            }

            return $order->fresh();
        });
    }

    public function approve(int $orderId, ?User $actor): WorkOrder
    {
        return $this->transition($orderId, $actor, false);
    }

    public function start(int $orderId, ?User $actor): WorkOrder
    {
        return $this->transition($orderId, $actor, true);
    }

    public function pendingRejections(): Builder
    {
        $this->checkSchema();

        return ProductCycle::where('quality_result', ProductCycle::RESULT_REJECTED)->where('quality_completed', true)
            ->whereNotNull('completed_at')->whereDoesntHave('reviewAssociation')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('product_cycles as later_cycle')
                    ->whereColumn('later_cycle.product_id', 'product_cycles.product_id')
                    ->whereColumn('later_cycle.cycle_number', '>', 'product_cycles.cycle_number');
            });
    }

    public function incorporatePending(int $cycleId, ?User $actor): WorkOrderProduct
    {
        $this->authorize($actor, true);
        $cycle = ProductCycle::find($cycleId);
        if (! $cycle) {
            $this->fail('El rechazo ya no está disponible.');
        }

        return $this->enqueue($cycle, $actor);
    }

    private function transition(int $orderId, ?User $actor, bool $start): WorkOrder
    {
        $this->authorize($actor, true);

        return DB::transaction(function () use ($orderId, $actor, $start) {
            $order = $this->lockedOrder($orderId);
            $from = $this->catalog->id($start ? 'approved_order_status' : 'new_order_status');
            $to = $this->catalog->id($start ? 'in_process_status' : 'approved_order_status');
            $center = $this->catalog->id('review_center');
            if ($order->review_receiving_open || ! $order->review_closed_at) {
                $this->fail('Cierra la recepción antes de aprobar o iniciar esta orden.');
            }
            if ((int) $order->status_id === $to && (int) $order->wc_id === $center) {
                return $order;
            }
            if ((int) $order->status_id !== $from) {
                $this->fail('El estado actual no permite esta operación.');
            }
            $order->quant = $this->validateMembers($order);
            $order->status_id = $to;
            $order->wc_id = $center;
            $order->wc_changed_at = now()->toDateString();
            $order->save();
            foreach ($order->productAssociations()->where('purpose', WorkOrderProduct::PURPOSE_REVIEW)->get() as $association) {
                if ($start) {
                    $association->product->update(['location_id' => $this->catalog->id('review_location')]);
                }
                $this->audit($association, $actor, $start ? 'Review started' : 'Review approved', $start ? 'Orden de revisión iniciada.' : 'Orden de revisión aprobada.', $association->rejectedCycle);
            }

            return $order->fresh();
        });
    }

    private function validateMembers(WorkOrder $order): int
    {
        $members = $order->productAssociations()->where('purpose', WorkOrderProduct::PURPOSE_REVIEW)->with('product.activeAssignment', 'rejectedCycle')->get();
        $count = $members->count();
        if ($count === 0 || $count > WorkOrder::REVIEW_CAPACITY) {
            $this->fail('La orden debe contener entre 1 y 50 productos.');
        }
        foreach ($members as $member) {
            if (! $member->rejectedCycle || ! $member->rejectedCycle->quality_completed
                || $member->rejectedCycle->quality_result !== ProductCycle::RESULT_REJECTED
                || ! $member->rejectedCycle->completed_at || $member->state !== WorkOrderProduct::STATE_ACTIVE
                || $member->product->qualified
                || (int) $member->product->cycles()->reorder()->orderByDesc('cycle_number')->value('id') !== (int) $member->rejected_cycle_id
                || (int) $member->product->work_order_id !== $order->id
                || (int) $member->product->activeAssignment?->work_order_product_id !== $member->id
                || (int) $member->product->activeAssignment?->product_cycle_id !== $member->rejected_cycle_id) {
                $this->fail('Un producto de esta orden tiene una asignación inconsistente. Contacta al administrador.');
            }
        }

        return $count;
    }

    private function lockedOrder(int $orderId): WorkOrder
    {
        $type = $this->lockCatalog();
        $order = WorkOrder::whereKey($orderId)->lockForUpdate()->first();
        if (! $order || (int) $order->type_id !== $type) {
            $this->fail('La orden seleccionada no es una orden Review.');
        }

        return $order;
    }

    private function closeReceiving(WorkOrder $order, int $actorId): void
    {
        $order->review_receiving_open = false;
        $order->review_closed_at = now();
        $order->review_closed_by = $actorId;
        $order->save();
    }

    private function audit(WorkOrderProduct $association, User $actor, string $process, string $description, ProductCycle $cycle): void
    {
        $association->product->history()->create([
            'process' => $process, 'description' => $description, 'date' => now(), 'user_id' => $actor->id,
            'location' => 'Review', 'work_order_id' => $association->work_order_id,
            'work_order_product_id' => $association->id, 'product_cycle_id' => $cycle->id,
            'metadata' => ['serial' => $association->product->serial, 'rejected_cycle_id' => $cycle->id],
        ]);
    }

    private function nextName(): string
    {
        $suffix = 1;
        foreach (WorkOrder::where('name', 'like', 'WR%')->pluck('name') as $name) {
            if (preg_match('/^WR(\d+)$/', $name, $matches)) {
                $suffix = max($suffix, (int) $matches[1] + 1);
            }
        }

        return 'WR'.str_pad((string) $suffix, 6, '0', STR_PAD_LEFT);
    }

    private function checkSchema(): void
    {
        if (! Schema::hasColumn('work_order_products', 'rejected_cycle_id') || ! Schema::hasColumn('work_orders', 'review_receiving_open')) {
            $this->fail('Review requiere preparación del administrador.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['review' => $message]);
    }
}
