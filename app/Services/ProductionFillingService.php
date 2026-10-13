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
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductionFillingService
{
    public function __construct(private ProductionCatalog $catalog, private ProductTraceabilityService $traceability) {}

    public function orders(): Builder
    {
        return WorkOrder::query()->whereIn('type_id', [$this->catalog->id('production_type'), $this->catalog->id('review_type')])
            ->where('status_id', $this->catalog->id('in_process_status'))
            ->where('wc_id', $this->catalog->id('filling_center'));
    }

    public function authorize(?User $actor): void
    {
        abort_unless($actor?->hasRole(['Admin', 'Manager', 'Worker']), 403);
    }

    public function checkOrder(WorkOrder $order, ?User $actor): void
    {
        $this->authorize($actor);
        if (! in_array((int) $order->type_id, [$this->catalog->id('production_type'), $this->catalog->id('review_type')], true)
            || (int) $order->status_id !== $this->catalog->id('in_process_status')
            || (int) $order->wc_id !== $this->catalog->id('filling_center')) {
            $this->fail('workOrder', 'La orden no está habilitada en la estación de llenado.');
        }
        if (! Schema::hasTable('product_cycles') || ! Schema::hasTable('product_active_assignments')
            || ! Schema::hasColumn('products', 'production_work_order_id')) {
            $this->fail('workOrder', 'El llenado requiere preparación del administrador.');
        }
        if (app(RepairCycleService::class)->isReview($order)) {
            app(RepairCycleService::class)->checkOrder($order);
        }
    }

    public function select(int $orderId, string $serial, ?User $actor): Products
    {
        $this->authorize($actor);
        $order = WorkOrder::find($orderId);
        if (! $order) {
            $this->fail('workOrder', 'La orden de trabajo ya no está disponible.');
        }
        $this->checkOrder($order, $actor);
        $serial = strtoupper(trim($serial));
        if ($serial === '' || strlen($serial) > 255) {
            $this->fail('serialInput', 'Escanea un número de serie válido.');
        }
        $matches = Products::where('serial', $serial)->limit(2)->get();
        if ($matches->isEmpty()) {
            $this->fail('serialInput', 'Número de serie no encontrado.');
        }
        if ($matches->count() !== 1) {
            $this->fail('serialInput', 'Este serial tiene registros duplicados. Contacta al administrador.');
        }
        $product = $matches->first();
        $this->checkProduct($order, $product);

        return $product->load('asset');
    }

    public function fill(int $orderId, int $productId, string $serial, mixed $weight, ?User $actor): array
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($orderId, $productId, $serial, $weight, $actor) {
            if ((int) WorkOrder::whereKey($orderId)->value('type_id') === $this->catalog->id('review_type')) {
                app(ReviewWorkOrderService::class)->lockCatalog();
            }
            $order = WorkOrder::whereKey($orderId)->lockForUpdate()->first();
            if (! $order) {
                $this->fail('workOrder', 'La orden de trabajo ya no está disponible.');
            }
            $this->checkOrder($order, $actor);
            $product = Products::whereKey($productId)->lockForUpdate()->first();
            if (! $product || $product->serial !== strtoupper(trim($serial))) {
                $this->fail('serialInput', 'El producto identificado cambió. Escanea nuevamente el tubo.');
            }
            if (Products::where('serial', $product->serial)->count() !== 1) {
                $this->fail('serialInput', 'Este serial tiene registros duplicados. Contacta al administrador.');
            }
            $this->checkProduct($order, $product);
            $this->validateWeight($product, $weight);
            $location = $this->catalog->id('waiting_location');
            $waitingCenter = $this->catalog->id('waiting_center');
            $repairOrder = app(RepairCycleService::class)->isReview($order);
            [$association, $cycle] = $repairOrder
                ? app(RepairCycleService::class)->prepare($order, $product, $actor->id)
                : $this->prepareTraceability($order, $product);
            $this->traceability->claim($association, $cycle, $actor->id);
            $weight = number_format((float) $weight, 4, '.', '');
            $now = now();
            $cycle->update(['fill_weight' => $weight, 'fill_completed' => true, 'fill_by' => $actor->id, 'fill_date' => $now]);
            $product->update(['weight' => $weight, 'filled' => true, 'fill_by' => $actor->id, 'fill_date' => $now, 'location_id' => $location]);
            if ($repairOrder) {
                $product->update(['qualified' => false, 'f_weight' => null, 'quality_by' => null, 'quality_date' => null]);
            }
            $association->update(['state' => WorkOrderProduct::STATE_ACTIVE]);
            $product->history()->create([
                'process' => 'Filled', 'description' => 'Llenado identificado por serial.',
                'date' => $now, 'user_id' => $actor->id, 'location' => 'Filled Area',
                'work_order_id' => $order->id, 'work_order_product_id' => $association->id,
                'product_cycle_id' => $cycle->id,
                'product_repair_id' => $cycle->product_repair_id,
                'metadata' => ['serial' => $product->serial, 'weight_g' => $weight, 'cycle_number' => $cycle->cycle_number],
            ]);

            $completed = ! $this->pendingProducts($order)->exists();
            if ($completed) {
                $members = $repairOrder ? app(RepairCycleService::class)->members($order) : $order->products()->getQuery();
                foreach ($members->orderBy('id')->lockForUpdate()->get() as $member) {
                    if ($repairOrder) {
                        [, , $memberCycle] = app(RepairCycleService::class)->context($order, $member);
                        if (! $memberCycle?->fill_completed) {
                            $this->fail('workOrder', 'Un miembro de la orden todavía no completó su nuevo llenado.');
                        }
                    } else {
                        [, $memberCycle] = $this->prepareTraceability($order, $member);
                    }
                    if (! $memberCycle->quality_completed && ! $memberCycle->completed_at) {
                        $memberCycle->update(['waiting_started_on' => $now->toDateString(), 'quality_available_on' => $now->copy()->addDays(5)->toDateString()]);
                    }
                }
                $order->wc_id = $waitingCenter;
                $order->wc_changed_at = $now->toDateString();
                $order->save();
            }

            return ['product' => $product->fresh(), 'completed' => $completed];
        });
    }

    public function pendingProducts(WorkOrder $order): Builder
    {
        if (app(RepairCycleService::class)->isReview($order)) {
            return app(RepairCycleService::class)->pending($order, 'fill');
        }

        return $order->products()->getQuery()->where(function ($query) {
            $query->where('filled', false)->orWhereNull('filled');
        });
    }

    public function validateWeight(Products $product, mixed $weight): void
    {
        if (! is_scalar($weight) || ! is_numeric($weight) || ! is_finite((float) $weight)) {
            $this->fail('currentProductData.weight', 'Indica un peso válido en gramos, con hasta cuatro decimales.');
        }
        Validator::make(['currentProductData' => ['weight' => $weight]], [
            'currentProductData.weight' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
        ], ['currentProductData.weight.*' => 'Indica un peso válido en gramos, con hasta cuatro decimales.'])->validate();
        $asset = $product->asset()->first();
        if (! $asset || ! is_finite((float) $asset->weight) || ! is_finite((float) $asset->weight_tolerance)
            || (float) $asset->weight <= 0 || (float) $asset->weight_tolerance < 0) {
            $this->fail('currentProductData.weight', 'El peso o la tolerancia del modelo requiere revisión del administrador.');
        }
        $min = (float) $asset->weight - (float) $asset->weight_tolerance;
        $max = (float) $asset->weight + (float) $asset->weight_tolerance;
        if ((float) $weight < $min || (float) $weight > $max) {
            $this->fail('currentProductData.weight', "El peso debe estar entre {$min} y {$max} g.");
        }
    }

    private function checkProduct(WorkOrder $order, Products $product): void
    {
        if (app(RepairCycleService::class)->isReview($order)) {
            app(RepairCycleService::class)->checkFilling($order, $product);

            return;
        }
        if ((int) $product->work_order_id !== $order->id) {
            $this->fail('serialInput', 'Este tubo no pertenece al Work Order actual.');
        }
        if (! $product->assambled) {
            $this->fail('serialInput', 'El producto todavía no ha terminado ensamblado.');
        }
        $cycle = $product->cycles()->reorder()->orderByDesc('cycle_number')->first();
        if ($product->filled || $product->qualified || $cycle?->fill_completed || $cycle?->quality_completed || $cycle?->completed_at) {
            $this->fail('serialInput', 'Este tubo ya completó el llenado. No se modificó su peso.');
        }
        if ($cycle && ($cycle->source === ProductCycle::SOURCE_REPAIR
            || ($cycle->association && (int) $cycle->association->work_order_id !== $order->id))) {
            $this->fail('serialInput', 'El ciclo de este tubo no está habilitado para esta orden.');
        }
        $assignment = $product->activeAssignment;
        if ($assignment?->association && ((int) $assignment->association->work_order_id !== $order->id
            || $assignment->association->purpose !== WorkOrderProduct::PURPOSE_PRODUCTION)) {
            $this->fail('serialInput', 'El producto ya pertenece a otra orden operativa.');
        }
        if ($product->production_work_order_id !== null && (int) $product->production_work_order_id !== $order->id) {
            $this->fail('serialInput', 'La orden original de este producto requiere revisión del administrador.');
        }
    }

    private function prepareTraceability(WorkOrder $order, Products $product): array
    {
        $association = $this->traceability->recordProductionOrigin($product, $order, $this->catalog->id('production_type'));
        $cycle = $product->cycles()->reorder()->orderByDesc('cycle_number')->first();
        if (! $cycle) {
            $raw = $product->getRawOriginal();
            $legacy = $product->weight !== null || $product->fill_date !== null || $product->f_weight !== null || $product->quality_date !== null;
            $cycle = ProductCycle::create([
                'product_id' => $product->id, 'work_order_product_id' => $association->id,
                'cycle_number' => 1, 'source' => $legacy ? ProductCycle::SOURCE_LEGACY : ProductCycle::SOURCE_PRODUCTION,
                'asset_id' => $product->asset_id, 'fill_weight' => $product->weight, 'quality_weight' => $product->f_weight,
                'fill_completed' => $product->filled, 'quality_completed' => (bool) $product->qualified,
                'quality_result' => $product->qualified ? ProductCycle::RESULT_APPROVED : null,
                'fill_by' => $product->fill_by, 'quality_by' => $product->quality_by,
                'fill_date' => $product->fill_date, 'quality_date' => $product->quality_date,
                'legacy_snapshot' => $legacy ? $raw : null,
            ]);
        }

        return [$association, $cycle];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
