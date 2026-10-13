<?php

namespace App\Services;

use App\Models\ProductCycle;
use App\Models\Products;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderProduct;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductionQualityService
{
    public function __construct(private ProductionCatalog $catalog, private ProductTraceabilityService $traceability) {}

    public function orders(): Builder
    {
        return WorkOrder::whereIn('type_id', [$this->catalog->id('production_type'), $this->catalog->id('review_type')])
            ->where('status_id', $this->catalog->id('in_process_status'))
            ->where('wc_id', $this->catalog->id('quality_center'));
    }

    public function authorize(?User $actor): void
    {
        abort_unless($actor?->hasRole(['Admin', 'Manager', 'Worker']), 403);
    }

    public function checkOrder(WorkOrder $order, ?User $actor): void
    {
        $this->authorize($actor);
        if (! $this->orders()->whereKey($order->id)->exists()) {
            $this->fail('workOrder', 'La orden no está habilitada en la estación de calidad.');
        }
        if (! Schema::hasTable('product_cycles')
            || ! Schema::hasTable('work_order_products') || ! Schema::hasTable('product_active_assignments')
            || ! Schema::hasColumn('products', 'production_work_order_id')) {
            $this->fail('workOrder', 'Calidad requiere preparación del administrador.');
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
        if ($matches->count() !== 1) {
            $this->fail('serialInput', $matches->isEmpty() ? 'Número de serie no encontrado.' : 'Este serial tiene registros duplicados. Contacta al administrador.');
        }
        $product = $matches->first();
        $this->checkProduct($order, $product);

        return $product->load('asset');
    }

    public function measure(int $orderId, int $productId, string $serial, mixed $weight, ?User $actor): array
    {
        $product = $this->select($orderId, $serial, $actor);
        if ($product->id !== $productId) {
            $this->fail('serialInput', 'El producto identificado cambió. Escanea nuevamente el tubo.');
        }

        return $this->comparison($product, $weight);
    }

    public function complete(int $orderId, int $productId, string $serial, mixed $weight, ?User $actor, ?array $confirmedRejection = null): array
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($orderId, $productId, $serial, $weight, $actor, $confirmedRejection) {
            // Review's shared lock comes before any order/product locks.
            if ($confirmedRejection !== null || (int) WorkOrder::whereKey($orderId)->value('type_id') === $this->catalog->id('review_type')) {
                app(ReviewWorkOrderService::class)->lockCatalog();
            }
            $order = WorkOrder::whereKey($orderId)->lockForUpdate()->first();
            if (! $order) {
                $this->fail('workOrder', 'La orden de trabajo ya no está disponible.');
            }
            $this->checkOrder($order, $actor);
            $product = Products::whereKey($productId)->lockForUpdate()->first();
            if (! $product || $product->serial !== strtoupper(trim($serial)) || Products::where('serial', $product->serial)->count() !== 1) {
                $this->fail('serialInput', 'El producto identificado cambió o su serial está duplicado. Escanea nuevamente.');
            }
            $this->checkProduct($order, $product);
            $measurement = $this->comparison($product, $weight);
            if (! $measurement['approved'] && ($confirmedRejection === null || $confirmedRejection !== $measurement)) {
                $this->fail('confirmation', 'Revisa la medición actual y confirma el envío a revisión.');
            }
            if ($confirmedRejection !== null && $measurement['approved']) {
                $this->fail('confirmation', 'El peso actual cambió. Vuelve a evaluar el tubo.');
            }
            $ready = $this->catalog->id('ready_order_status');
            $location = $this->catalog->id($measurement['approved'] ? 'stock_location' : 'quality_location');
            $status = $this->catalog->id($measurement['approved'] ? 'ready_product_status' : 'in_process_product_status');
            $repairOrder = app(RepairCycleService::class)->isReview($order);
            $association = $repairOrder
                ? app(RepairCycleService::class)->context($order, $product)[0]
                : $this->traceability->recordProductionOrigin($product, $order, $this->catalog->id('production_type'));
            $cycle = $this->cycle($product);
            if (! $cycle) {
                [$started, $available] = $this->waitingDates($order, $product, null);
                $cycle = ProductCycle::create([
                    'product_id' => $product->id, 'work_order_product_id' => $association->id,
                    'cycle_number' => 1, 'source' => ProductCycle::SOURCE_LEGACY, 'asset_id' => $product->asset_id,
                    'fill_weight' => $measurement['fill_weight'], 'fill_completed' => true,
                    'fill_by' => $product->fill_by, 'fill_date' => $product->fill_date,
                    'quality_weight' => $product->f_weight, 'quality_completed' => false,
                    'quality_by' => $product->quality_by, 'quality_date' => $product->quality_date,
                    'waiting_started_on' => $started, 'quality_available_on' => $available,
                    'legacy_snapshot' => $product->getRawOriginal(),
                ]);
            }
            $this->traceability->claim($association, $cycle, $actor->id);
            $now = now();
            $cycle->update([
                'quality_weight' => $measurement['quality_weight'], 'quality_completed' => true,
                'quality_result' => $measurement['approved'] ? ProductCycle::RESULT_APPROVED : ProductCycle::RESULT_REJECTED,
                'quality_by' => $actor->id, 'quality_date' => $now, 'completed_at' => $now,
            ]);
            $product->update([
                'f_weight' => $measurement['quality_weight'], 'qualified' => $measurement['approved'],
                'quality_by' => $actor->id, 'quality_date' => $now, 'location_id' => $location, 'status_id' => $status,
                'work_order_id' => $measurement['approved'] ? null : $order->id,
            ]);
            $product->history()->create([
                'process' => $measurement['approved'] ? 'Qualified' : 'Quality rejected',
                'description' => $measurement['approved'] ? 'Calidad aprobada por comparación de pesos.' : 'Rechazo confirmado y enviado a una orden de revisión.',
                'date' => $now, 'user_id' => $actor->id, 'location' => 'Quality Area',
                'work_order_id' => $order->id, 'work_order_product_id' => $association->id, 'product_cycle_id' => $cycle->id,
                'product_repair_id' => $cycle->product_repair_id,
                'metadata' => array_merge($measurement, ['serial' => $product->serial, 'tolerance_g' => '5.0000', 'review_pending' => false]),
            ]);
            if ($measurement['approved']) {
                $association->update(['state' => WorkOrderProduct::STATE_COMPLETED, 'closed_at' => $now]);
                $this->traceability->release($association);
            } else {
                $association->update(['state' => WorkOrderProduct::STATE_ACTIVE]);
                $reviewAssociation = app(ReviewWorkOrderService::class)->enqueue($cycle, $actor);
            }
            $completed = ! $this->pendingProducts($order)->exists();
            if ($completed) {
                $order->wc_id = null;
                $order->status_id = $ready;
                $order->wc_changed_at = $now->toDateString();
                $order->save();
                $rejected = ProductCycle::whereHas('association', fn ($query) => $query->where('work_order_id', $order->id))
                    ->where('quality_result', ProductCycle::RESULT_REJECTED)->exists();
                if (! $repairOrder && ! $rejected && $order->for) {
                    $parent = WorkOrder::whereKey($order->for)->lockForUpdate()->first();
                    if ($parent && (int) $parent->type_id === $this->catalog->id('distribution_type')
                        && (int) $parent->status_id !== $ready) {
                        $parent->status_id = $this->catalog->id('in_process_status');
                        $parent->save();
                    }
                }
            }

            return ['product' => $product->fresh(), 'completed' => $completed, 'measurement' => $measurement,
                'review_order' => isset($reviewAssociation) ? $reviewAssociation->workOrder : null];
        });
    }

    public function pendingProducts(WorkOrder $order): Builder
    {
        if (app(RepairCycleService::class)->isReview($order)) {
            return app(RepairCycleService::class)->pending($order, 'quality');
        }

        return $order->products()->getQuery()->where(function ($query) {
            $query->where('qualified', false)->orWhereNull('qualified');
        })->whereDoesntHave('cycles', function ($query) {
            $query->where('quality_completed', true)->whereNotExists(function ($later) {
                $later->selectRaw('1')->from('product_cycles as later_cycle')
                    ->whereColumn('later_cycle.product_id', 'product_cycles.product_id')
                    ->whereColumn('later_cycle.cycle_number', '>', 'product_cycles.cycle_number');
            });
        });
    }

    private function comparison(Products $product, mixed $weight): array
    {
        if (! is_scalar($weight) || ! is_numeric($weight) || ! is_finite((float) $weight)) {
            $this->fail('currentProductData.weight', 'Indica un peso válido en gramos.');
        }
        Validator::make(['currentProductData' => ['weight' => $weight]], [
            'currentProductData.weight' => ['required', 'numeric', 'gt:0', 'lt:10000000000', 'decimal:0,4'],
        ], ['currentProductData.weight.*' => 'Indica un peso positivo en gramos con hasta cuatro decimales.'])->validate();
        $fill = BigDecimal::of($this->fillWeight($product));
        $quality = BigDecimal::of((string) $weight)->toScale(4);
        $difference = $quality->minus($fill);

        return ['fill_weight' => (string) $fill, 'quality_weight' => (string) $quality,
            'difference_g' => (string) $difference, 'approved' => $difference->abs()->isLessThanOrEqualTo(5)];
    }

    private function fillWeight(Products $product): string
    {
        $cycle = $this->cycle($product);
        $weight = $cycle ? $cycle->fill_weight : $product->weight;
        if (! is_numeric($weight) || ! is_finite((float) $weight) || (float) $weight <= 0 || (float) $weight >= 10000000000) {
            $this->fail('serialInput', 'No se puede verificar el peso de llenado de este tubo. Contacta al administrador.');
        }

        return $cycle ? (string) BigDecimal::of((string) $weight)->toScale(4) : number_format((float) $weight, 4, '.', '');
    }

    private function checkProduct(WorkOrder $order, Products $product): void
    {
        if ((int) $product->work_order_id !== $order->id) {
            $this->fail('serialInput', 'Este tubo no pertenece al Work Order actual.');
        }
        $cycle = $this->cycle($product);
        if (! $product->assambled || ! $product->filled || ($cycle && ! $cycle->fill_completed)) {
            $this->fail('serialInput', 'Este tubo todavía no ha terminado ensamblado y llenado.');
        }
        if ($product->qualified || $cycle?->quality_completed || $cycle?->completed_at) {
            $this->fail('serialInput', 'Este tubo ya completó calidad en el ciclo actual.');
        }
        if ((int) $product->location_id !== $this->catalog->id('quality_location')) {
            $this->fail('serialInput', 'Este tubo no está disponible en el área de calidad.');
        }
        $repairOrder = app(RepairCycleService::class)->isReview($order);
        if ($repairOrder) {
            [, , $repairCycle] = app(RepairCycleService::class)->context($order, $product);
            if (! $repairCycle || $repairCycle->id !== $cycle?->id) {
                $this->fail('serialInput', 'Este tubo todavía no tiene un nuevo ciclo de llenado.');
            }
        }
        if (! $repairOrder && (($product->production_work_order_id !== null && (int) $product->production_work_order_id !== $order->id)
            || ($cycle && ($cycle->source === ProductCycle::SOURCE_REPAIR
                || ($cycle->association && ((int) $cycle->association->work_order_id !== $order->id
                    || $cycle->association->purpose !== WorkOrderProduct::PURPOSE_PRODUCTION)))))) {
            $this->fail('serialInput', 'El ciclo de este tubo no está habilitado para esta orden.');
        }
        $assignment = $product->activeAssignment;
        if (! $repairOrder && $assignment?->association && ((int) $assignment->association->work_order_id !== $order->id
            || $assignment->association->purpose !== WorkOrderProduct::PURPOSE_PRODUCTION)) {
            $this->fail('serialInput', 'El producto ya pertenece a otra orden operativa.');
        }
        [, $available] = $this->waitingDates($order, $product, $cycle);
        if (now()->toDateString() < $available) {
            $this->fail('serialInput', "Este tubo debe permanecer en espera hasta el {$available}.");
        }
        $this->fillWeight($product);
    }

    private function waitingDates(WorkOrder $order, Products $product, ?ProductCycle $cycle): array
    {
        if (app(RepairCycleService::class)->isReview($order)
            && (! $cycle?->waiting_started_on || ! $cycle?->quality_available_on)) {
            $this->fail('serialInput', 'No se pueden verificar las fechas de espera del nuevo ciclo.');
        }
        if ($cycle?->waiting_started_on && $cycle?->quality_available_on) {
            $start = $cycle->waiting_started_on->copy()->startOfDay();
            $available = $cycle->quality_available_on->copy()->startOfDay();
            if ($available->lt($start->copy()->addDays(5))) {
                $this->fail('serialInput', 'Las fechas de espera requieren revisión del administrador.');
            }
        } else {
            // The legacy scheduler overwrites wc_changed_at on arrival at Quality.
            // Use the latest verified fill date of the order, never that overwritten date.
            $dates = Products::where(function ($query) use ($order) {
                $query->where('work_order_id', $order->id)->orWhere('production_work_order_id', $order->id);
            })->pluck('fill_date');
            if ($dates->isEmpty() || $dates->contains(fn ($date) => ! $date)) {
                $this->fail('serialInput', 'No se puede verificar el período de espera. Contacta al administrador.');
            }
            $start = Carbon::parse($dates->max())->startOfDay();
            $available = $start->copy()->addDays(5);
        }
        if ($cycle?->fill_date && $start->lt($cycle->fill_date->copy()->startOfDay())) {
            $this->fail('serialInput', 'Las fechas de espera requieren revisión del administrador.');
        }

        return [$start->toDateString(), $available->toDateString()];
    }

    private function cycle(Products $product): ?ProductCycle
    {
        return $product->cycles()->reorder()->orderByDesc('cycle_number')->first();
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
