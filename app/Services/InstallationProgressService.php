<?php

namespace App\Services;

use App\Models\Installation;
use App\Models\InstallationStep;
use App\Models\InvoiceEvent;
use App\Models\WorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InstallationProgressService
{
    public function startIfNeeded(Installation $installation): void
    {
        DB::transaction(function () use ($installation) {
            [$locked, $order] = $this->lockedRecords($installation);
            if (! in_array($locked->status, ['draft', 'scheduled', 'in_progress'], true)) {
                return;
            }
            if ($order) {
                $catalog = app(ProductionCatalog::class);
                if (! in_array((int) $order->status_id, [$catalog->id('new_order_status'), $catalog->id('in_process_status')], true)) {
                    throw ValidationException::withMessages(['workOrder' => 'This Installation Work Order cannot be started.']);
                }
                $this->moveOrder($locked, $order, $catalog->id('in_process_status'), 'Installation WorkOrder Started');
            }
            if ($locked->status !== 'in_progress') {
                $locked->update(['status' => 'in_progress']);
            }
            $installation->refresh();
        }, 3);
    }

    public function markStep(InstallationStep $step, bool $isDone, ?string $notes = null): void
    {
        $step->update([
            'is_done' => $isDone,
            'done_at' => $isDone ? Carbon::now() : null,
            'notes' => $notes,
        ]);
    }

    public function completeIfAllDone(Installation $installation): bool
    {
        return DB::transaction(function () use ($installation) {
            [$locked, $order] = $this->lockedRecords($installation);
            if ($locked->steps()->where('is_done', false)->exists()) {
                return false;
            }
            if ($order) {
                $catalog = app(ProductionCatalog::class);
                if (! in_array((int) $order->status_id, [$catalog->id('new_order_status'), $catalog->id('in_process_status'), $catalog->id('ready_order_status')], true)) {
                    throw ValidationException::withMessages(['workOrder' => 'This Installation Work Order cannot be completed.']);
                }
                $this->moveOrder($locked, $order, $catalog->id('ready_order_status'), 'Installation WorkOrder Completed');
            }
            if ($locked->status !== 'completed' || $locked->performed_at === null) {
                $locked->update(['status' => 'completed', 'performed_at' => $locked->performed_at ?? Carbon::now()]);
            }
            $installation->refresh();

            return true;
        }, 3);
    }

    private function lockedRecords(Installation $installation): array
    {
        $current = Installation::findOrFail($installation->id);
        $order = $current->work_order_id ? WorkOrder::whereKey($current->work_order_id)->lockForUpdate()->firstOrFail() : null;
        $locked = Installation::whereKey($installation->id)->lockForUpdate()->firstOrFail();
        if ($locked->work_order_id !== $current->work_order_id || ($order && ! $order->isInstallationOrder())) {
            throw ValidationException::withMessages(['workOrder' => 'The Installation Work Order requires review.']);
        }

        return [$locked, $order];
    }

    private function moveOrder(Installation $installation, WorkOrder $order, int $status, string $event): void
    {
        if ((int) $order->status_id === $status) {
            return;
        }
        $order->update(['status_id' => $status]);
        InvoiceEvent::create(['event' => $event, 'installation_id' => $installation->id,
            'work_order_id' => $order->id, 'user_id' => auth()->id()]);
    }
}
