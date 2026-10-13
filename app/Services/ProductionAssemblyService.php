<?php

namespace App\Services;

use App\Http\Controllers\SerialController;
use App\Models\Products;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionAssemblyService
{
    public function __construct(private ProductionCatalog $catalog, private ProductTraceabilityService $traceability) {}

    public function authorize(?User $actor): void
    {
        abort_unless($actor?->hasRole(['Admin', 'Manager', 'Worker']), 403);
    }

    public function orders()
    {
        return WorkOrder::where('type_id', $this->catalog->id('production_type'))
            ->where('status_id', $this->catalog->id('in_process_status'))->where('wc_id', $this->catalog->id('assemble_center'));
    }

    public function checkOrder(WorkOrder $order, ?User $actor): void
    {
        $this->authorize($actor);
        if (! $this->orders()->whereKey($order->id)->exists()) {
            throw ValidationException::withMessages(['workOrder' => 'La orden no está habilitada en ensamblado.']);
        }
    }

    public function assemble(int $orderId, int $productId, string $serial, bool $completed, ?User $actor): Products
    {
        $this->authorize($actor);
        if (! $completed) {
            throw ValidationException::withMessages(['assambled' => 'Confirma que el tubo terminó ensamblado antes de continuar.']);
        }

        return DB::transaction(function () use ($orderId, $productId, $serial, $actor) {
            $order = WorkOrder::whereKey($orderId)->lockForUpdate()->firstOrFail();
            $this->checkOrder($order, $actor);
            $pending = $order->products()->where(fn ($query) => $query->where('assambled', false)->orWhereNull('assambled'));
            if ((int) (clone $pending)->orderBy('id')->value('id') !== $productId) {
                throw ValidationException::withMessages(['serialInput' => 'El producto actual cambió. Recarga la orden para continuar en secuencia.']);
            }
            $product = Products::whereKey($productId)->lockForUpdate()->firstOrFail();
            $product = SerialController::assemble($product, ['assambled' => true, 'assambly_by' => $actor->id, 'assambly_date' => now()], $serial);
            $association = $this->traceability->recordProductionOrigin($product, $order, $this->catalog->id('production_type'));
            $this->traceability->claim($association, null, $actor->id);
            $association->update(['state' => 'active']);
            $product->history()->create(['process' => 'Assembled', 'description' => 'Ensamblado secuencial completado.',
                'date' => now(), 'user_id' => $actor->id, 'location' => 'Production', 'work_order_id' => $order->id,
                'work_order_product_id' => $association->id, 'metadata' => ['serial' => $product->serial]]);
            if (! $pending->exists()) {
                $order->update(['wc_id' => $this->catalog->id('filling_center'), 'wc_changed_at' => now()->toDateString()]);
            }

            return $product->fresh();
        });
    }
}
