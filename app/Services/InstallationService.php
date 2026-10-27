<?php

namespace App\Services;

use App\Http\Controllers\WorkOrderController;
use App\Models\Customer;
use App\Models\Installation;
use App\Models\InvoiceEvent;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InstallationService
{
    public function create(array $data, bool $cloneSteps = true): Installation
    {
        return DB::transaction(function () use ($data, $cloneSteps) {
            $installation = Installation::create([
                ...$data,
                'customer_quickbooks_id' => $data['customer_quickbooks_id']
                    ?? optional(Customer::find($data['customer_id']))->quickbooks_id,
            ]);
            $this->ensureWorkOrder($installation);
            $this->syncAssignment($installation);
            if ($cloneSteps) {
                $this->cloneTemplateSteps($installation);
            }

            return $installation->refresh();
        });
    }

    /** Called explicitly on new installations only; never backfills historical records. */
    public function ensureWorkOrder(Installation $installation): WorkOrder
    {
        return DB::transaction(function () use ($installation) {
            $locked = Installation::whereKey($installation->id)->lockForUpdate()->firstOrFail();
            if ($locked->work_order_id) {
                $order = WorkOrder::findOrFail($locked->work_order_id);
                if (! $order->isInstallationOrder() || (string) $order->customer_quickbooks_id !== (string) $locked->customer_quickbooks_id) {
                    throw ValidationException::withMessages(['workOrder' => 'The linked Installation Work Order requires review.']);
                }
                $installation->setAttribute('work_order_id', $order->id);

                return $order;
            }
            $catalog = app(ProductionCatalog::class);
            $type = $catalog->id('installation_type');
            // Same type lock used by Invoice classification, so WI names cannot race.
            DB::table('wo_type')->where('id', $type)->lockForUpdate()->first();
            $order = WorkOrder::create(['type_id' => $type, 'status_id' => $catalog->id('new_order_status'),
                'customer_quickbooks_id' => $locked->customer_quickbooks_id,
                'name' => WorkOrderController::WOname('WI'), 'date' => now()]);
            $locked->update(['work_order_id' => $order->id]);
            $installation->setAttribute('work_order_id', $order->id);
            InvoiceEvent::create(['event' => 'Installation WorkOrder Created', 'work_order_id' => $order->id,
                'installation_id' => $locked->id, 'user_id' => auth()->id()]);

            return $order;
        });
    }

    public function syncAssignment(Installation $installation): void
    {
        $installation->assignment()->updateOrCreate(
            ['installation_id' => $installation->id],
            [
                'customer_id' => $installation->customer_id,
                'equipment_id' => $installation->equipment_id,
            ]
        );
    }

    public function cloneTemplateSteps(Installation $installation): void
    {
        $templates = $installation->equipment
            ->installationTemplateSteps()
            ->orderBy('sort_order')
            ->get();

        $installation->steps()->delete();

        foreach ($templates as $template) {
            $installation->steps()->create([
                'title' => $template->title,
                'description' => $template->description,
                'sort_order' => $template->sort_order,
                'is_required' => $template->is_required,
                'img' => $template->img,
                'is_done' => false,
                'done_at' => null,
                'notes' => null,
            ]);
        }
    }
}
