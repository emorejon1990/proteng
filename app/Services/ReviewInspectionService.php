<?php

namespace App\Services;

use App\Models\ProductRepair;
use App\Models\Products;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReviewInspectionService
{
    public function __construct(private ProductionCatalog $catalog, private ReviewWorkOrderService $reviews, private ProductTraceabilityService $traceability) {}

    public function orders(): Builder
    {
        return WorkOrder::where('type_id', $this->catalog->id('review_type'))
            ->where('status_id', $this->catalog->id('in_process_status'))
            ->where('wc_id', $this->catalog->id('review_center'));
    }

    public function checkOrder(WorkOrder $order, ?User $actor): void
    {
        $this->reviews->authorize($actor);
        if (! Schema::hasTable('product_repairs')) {
            $this->fail('workOrder', 'La estación de revisión requiere preparación del administrador.');
        }
        if (! $this->orders()->whereKey($order->id)->exists() || $order->review_receiving_open || ! $order->review_closed_at) {
            $this->fail('workOrder', 'La orden no está habilitada en la estación de revisión.');
        }
    }

    public function select(int $orderId, string $serial, ?User $actor): WorkOrderProduct
    {
        $this->reviews->authorize($actor);
        $order = WorkOrder::find($orderId);
        if (! $order) {
            $this->fail('workOrder', 'La orden ya no está disponible.');
        }
        $this->checkOrder($order, $actor);
        $serial = strtoupper(trim($serial));
        if ($serial === '' || strlen($serial) > 255) {
            $this->fail('serialInput', 'Escanea un serial válido.');
        }
        $products = Products::where('serial', $serial)->limit(2)->get();
        if ($products->count() !== 1) {
            $this->fail('serialInput', $products->isEmpty() ? 'Número de serie no encontrado.' : 'El serial tiene registros duplicados. Contacta al administrador.');
        }

        return $this->checkProduct($order, $products->first())->load('product.asset', 'rejectedCycle');
    }

    public function validateDecision(array $data): array
    {
        foreach (['diagnosis', 'repair_description', 'recycling_reason'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }
        $validated = Validator::make(['decision' => $data], [
            'decision.result' => ['required', 'in:repaired,recycled'],
            'decision.diagnosis' => ['required', 'string', 'max:5000'],
            'decision.repair_description' => ['nullable', 'required_if:decision.result,repaired', 'string', 'max:5000'],
            'decision.recycling_reason' => ['nullable', 'required_if:decision.result,recycled', 'string', 'max:5000'],
        ])->validate()['decision'];

        return ['result' => $validated['result'], 'diagnosis' => $validated['diagnosis'],
            'repair_description' => $validated['result'] === ProductRepair::RESULT_REPAIRED ? $validated['repair_description'] : null,
            'recycling_reason' => $validated['result'] === ProductRepair::RESULT_RECYCLED ? $validated['recycling_reason'] : null];
    }

    public function inspect(int $orderId, int $productId, string $serial, array $data, ?User $actor, bool $recyclingConfirmed = false): array
    {
        $this->reviews->authorize($actor);
        $decision = $this->validateDecision($data);
        if ($decision['result'] === ProductRepair::RESULT_RECYCLED && ! $recyclingConfirmed) {
            $this->fail('confirmation', 'Confirma el reciclaje antes de guardar este resultado.');
        }

        return DB::transaction(function () use ($orderId, $productId, $serial, $decision, $actor) {
            $this->reviews->lockCatalog();
            $order = WorkOrder::whereKey($orderId)->lockForUpdate()->first();
            if (! $order) {
                $this->fail('workOrder', 'La orden ya no está disponible.');
            }
            $this->checkOrder($order, $actor);
            $product = Products::whereKey($productId)->lockForUpdate()->first();
            if (! $product || $product->serial !== strtoupper(trim($serial)) || Products::where('serial', $product->serial)->count() !== 1) {
                $this->fail('serialInput', 'El producto identificado cambió o su serial está duplicado. Escanea nuevamente.');
            }
            $association = $this->checkProduct($order, $product);
            $repair = ProductRepair::firstOrNew(['work_order_product_id' => $association->id]);
            if ($repair->exists && ((int) $repair->product_id !== $product->id || (int) $repair->rejected_cycle_id !== (int) $association->rejected_cycle_id)) {
                $this->fail('serialInput', 'El diagnóstico existente requiere revisión del administrador.');
            }
            $repair->fill(array_merge($decision, [
                'product_id' => $product->id, 'rejected_cycle_id' => $association->rejected_cycle_id,
                'reviewed_by' => $actor->id, 'reviewed_at' => now(),
            ]))->save();
            $recycled = $decision['result'] === ProductRepair::RESULT_RECYCLED;
            $product->location_id = $this->catalog->id($recycled ? 'recycle_location' : 'review_location');
            $product->status_id = $this->catalog->id($recycled ? 'recycled_product_status' : 'in_process_product_status');
            if ($recycled) {
                $product->work_order_id = null;
                $association->update(['state' => WorkOrderProduct::STATE_COMPLETED, 'closed_at' => now()]);
                $this->traceability->release($association);
            }
            $product->save();
            $product->history()->create([
                'process' => $recycled ? 'Recycled' : 'Repaired', 'description' => $decision['diagnosis'],
                'date' => now(), 'user_id' => $actor->id, 'location' => $recycled ? 'Recycle' : 'Review',
                'work_order_id' => $order->id, 'work_order_product_id' => $association->id,
                'product_cycle_id' => $association->rejected_cycle_id, 'product_repair_id' => $repair->id,
                'metadata' => array_merge($decision, ['serial' => $product->serial]),
            ]);
            $completed = ! $this->pendingMembers($order)->exists();
            if ($completed) {
                $repairable = $order->productAssociations()->where('purpose', 'review')
                    ->whereHas('repairs', fn ($query) => $query->where('result', ProductRepair::RESULT_REPAIRED)->whereNotNull('reviewed_at'))->exists();
                $order->wc_id = $repairable ? $this->catalog->id('filling_center') : null;
                $order->status_id = $this->catalog->id($repairable ? 'in_process_status' : 'ready_order_status');
                $order->wc_changed_at = now()->toDateString();
                $order->save();
            }

            return ['repair' => $repair, 'completed' => $completed, 'recycled' => $recycled];
        });
    }

    public function pendingMembers(WorkOrder $order): Builder
    {
        return $order->productAssociations()->getQuery()->where('purpose', 'review')
            ->whereDoesntHave('repairs', fn ($query) => $query->whereIn('result', ['repaired', 'recycled'])->whereNotNull('reviewed_at'));
    }

    private function checkProduct(WorkOrder $order, Products $product): WorkOrderProduct
    {
        $association = $order->productAssociations()->where('product_id', $product->id)->where('purpose', 'review')->first();
        if (! $association || (int) $product->work_order_id !== $order->id) {
            $this->fail('serialInput', 'Este tubo no pertenece a la orden de revisión actual.');
        }
        if ($association->repairs()->where(function ($query) {
            $query->whereNotNull('reviewed_at')->orWhere('result', '!=', ProductRepair::RESULT_PENDING);
        })->exists()) {
            $this->fail('serialInput', 'Este tubo ya recibió un resultado de revisión.');
        }
        $cycle = $association->rejectedCycle;
        $assignment = $product->activeAssignment;
        if ($association->state !== WorkOrderProduct::STATE_ACTIVE || ! $cycle || ! $cycle->quality_completed
            || $cycle->quality_result !== 'rejected' || ! $cycle->completed_at || $product->qualified
            || (int) $product->cycles()->reorder()->orderByDesc('cycle_number')->value('id') !== $cycle->id
            || (int) $assignment?->work_order_product_id !== $association->id
            || (int) $assignment?->product_cycle_id !== $cycle->id) {
            $this->fail('serialInput', 'La asignación o el ciclo de este tubo requiere revisión del administrador.');
        }
        $locations = [$this->catalog->id('quality_location'), $this->catalog->id('review_location')];
        if (! in_array((int) $product->location_id, $locations, true)
            || (int) $product->status_id === $this->catalog->id('recycled_product_status')) {
            $this->fail('serialInput', 'Este tubo no está disponible para revisión.');
        }

        return $association;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
