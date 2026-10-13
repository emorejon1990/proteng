<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkOrderLifecycleService
{
    public function __construct(private ProductionCatalog $catalog) {}

    public function approve(int $orderId, ?User $actor): WorkOrder
    {
        return $this->transition($orderId, $actor, false);
    }

    public function start(int $orderId, ?User $actor): WorkOrder
    {
        return $this->transition($orderId, $actor, true);
    }

    private function transition(int $orderId, ?User $actor, bool $start): WorkOrder
    {
        abort_unless($actor?->hasRole(['Admin', 'Manager']), 403);

        return DB::transaction(function () use ($orderId, $start) {
            $order = WorkOrder::whereKey($orderId)->lockForUpdate()->firstOrFail();
            $production = (int) $order->type_id === $this->catalog->id('production_type');
            if ((! $production && (int) $order->type_id !== $this->catalog->id('distribution_type'))
                || (int) $order->status_id !== $this->catalog->id($start ? 'approved_order_status' : 'new_order_status')) {
                throw ValidationException::withMessages(['workOrder' => 'El tipo o estado de la orden no permite esta transición.']);
            }
            if ($production && (! $order->asset || (int) $order->quant < 1 || (int) $order->quant != $order->quant)) {
                throw ValidationException::withMessages(['workOrder' => 'Indica un modelo y una cantidad positiva de producción.']);
            }
            if (! $production) {
                app(DistributionSelectionService::class)->requirements($order, true);
            }
            $order->status_id = $this->catalog->id($start ? 'in_process_status' : 'approved_order_status');
            if ($production) {
                $order->wc_id = $this->catalog->id('assemble_center');
            }
            $order->save();

            return $order->fresh();
        });
    }
}
