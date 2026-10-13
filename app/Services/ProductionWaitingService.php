<?php

namespace App\Services;

use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionWaitingService
{
    public function __construct(private ProductionCatalog $catalog, private RepairCycleService $repairs) {}

    public function orders()
    {
        return WorkOrder::whereIn('type_id', [$this->catalog->id('production_type'), $this->catalog->id('review_type')])
            ->where('status_id', $this->catalog->id('in_process_status'))
            ->where('wc_id', $this->catalog->id('waiting_center'));
    }

    public function advance(int $orderId): bool
    {
        return DB::transaction(function () use ($orderId) {
            if ((int) WorkOrder::whereKey($orderId)->value('type_id') === $this->catalog->id('review_type')) {
                app(ReviewWorkOrderService::class)->lockCatalog();
            }
            $order = WorkOrder::whereKey($orderId)->lockForUpdate()->first();
            if (! $order || ! $this->orders()->whereKey($orderId)->exists()) {
                return false;
            }
            $repairOrder = $this->repairs->isReview($order);
            if ($repairOrder) {
                $this->repairs->checkOrder($order);
            }
            $members = ($repairOrder ? $this->repairs->members($order) : $order->products()->getQuery())
                ->orderBy('id')->lockForUpdate()->get();
            if ($members->isEmpty()) {
                $this->fail('La orden en espera no tiene productos pendientes de calidad.');
            }
            $latestFill = $members->max('fill_date');
            $contexts = [];
            $due = true;
            foreach ($members as $product) {
                $cycle = $product->cycles()->reorder()->orderByDesc('cycle_number')->first();
                $association = $cycle?->association;
                if ($repairOrder) {
                    [$association, , $cycle] = $this->repairs->context($order, $product);
                }
                $assignment = $product->activeAssignment;
                if (! $repairOrder && (($cycle && $association?->purpose !== 'production')
                    || ($assignment?->work_order_product_id !== null
                        && ((int) $assignment->association?->work_order_id !== $order->id
                            || ($cycle && (int) $assignment->product_cycle_id !== $cycle->id))))) {
                    $this->fail('La asignación operativa de un tubo es inconsistente.');
                }
                if (! $product->filled || $product->qualified || ! $product->fill_date
                    || (int) $product->location_id !== $this->catalog->id('waiting_location')
                    || ($cycle && (! $cycle->fill_completed || $cycle->quality_completed || $cycle->completed_at
                        || (int) $association?->work_order_id !== $order->id))) {
                    $this->fail('Un tubo tiene datos de llenado o ubicación inconsistentes.');
                }
                if ($cycle?->waiting_started_on && $cycle?->quality_available_on) {
                    $start = $cycle->waiting_started_on->copy()->startOfDay();
                    $available = $cycle->quality_available_on->copy()->startOfDay();
                } else {
                    if ($repairOrder || ! $order->wc_changed_at) {
                        $this->fail('No se puede verificar el período de espera de este ciclo.');
                    }
                    // Preserve legacy waiting using verified calendar dates, not fixed IDs.
                    $start = Carbon::parse($order->wc_changed_at)->startOfDay();
                    $available = $start->copy()->addDays(5);
                }
                if ($start->lt(Carbon::parse($latestFill)->startOfDay())
                    || ($cycle?->fill_date && $start->lt($cycle->fill_date->copy()->startOfDay()))
                    || $available->lt($start->copy()->addDays(5))) {
                    $this->fail('Las fechas de espera son inconsistentes con el llenado.');
                }
                $due = $due && now()->toDateString() >= $available->toDateString();
                $contexts[] = [$product, $association, $cycle];
            }
            if (! $due) {
                return false;
            }
            $qualityCenter = $this->catalog->id('quality_center');
            $qualityLocation = $this->catalog->id('quality_location');
            foreach ($contexts as [$product, $association, $cycle]) {
                $product->update(['location_id' => $qualityLocation]);
                $product->history()->create([
                    'process' => 'Waiting completed', 'description' => 'Espera de cinco días calendario completada.',
                    'date' => now(), 'location' => 'Quality Area', 'work_order_id' => $order->id,
                    'work_order_product_id' => $association?->id, 'product_cycle_id' => $cycle?->id,
                    'product_repair_id' => $cycle?->product_repair_id,
                    'metadata' => ['serial' => $product->serial, 'automatic' => true],
                ]);
            }
            $order->update(['wc_id' => $qualityCenter, 'wc_changed_at' => now()->toDateString()]);

            return true;
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['workOrder' => $message]);
    }
}
