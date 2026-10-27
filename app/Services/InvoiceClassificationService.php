<?php

namespace App\Services;

use App\Http\Controllers\WorkOrderController;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\Installation;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InvoiceClassificationService
{
    public function __construct(private ProductionCatalog $catalog, private InvoiceRequirements $requirements, private InstallationService $installations) {}

    public function classify(int $id, string $type, ?User $actor, array $configuration = []): WorkOrder
    {
        abort_unless($actor?->hasRole(['Admin', 'Manager']) && ! $actor->hasRole('Customer'), 403);
        if (! in_array($type, ['distribution', 'installation'], true)) {
            $this->fail('Choose Distribution or Installation.');
        }

        return DB::transaction(function () use ($id, $type, $actor, $configuration) {
            $invoice = Invoice::whereKey($id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('classify', $invoice);
            if ($invoice->operation_status === 'out_of_scope') {
                $this->fail('Esta factura está fuera del alcance operativo.');
            }
            if ($invoice->operation_type || $invoice->work_order_id || $invoice->classified_at) {
                $this->fail('This invoice already has an operation.');
            }
            if ($invoice->sync_status !== 'synced' || ! $invoice->last_synced_at || $invoice->status === 'void') {
                $this->fail('Sync a valid invoice before classifying.');
            }
            if (! $invoice->customer?->quickbooks_id) {
                $this->fail('A local QuickBooks customer is required.');
            }
            $filters = app(InvoiceSyncService::class)->filtersFor($invoice->raw_payload ?? []);
            if ($filters['result'] !== 'candidate') {
                $this->fail('Only outstanding invoices with verified Product items can be classified.');
            }
            $requirements = $this->requirements->resolve($invoice, true, $filters);
            if ($type === 'installation') {
                $equipment = Equipment::whereKey($configuration['equipment_id'] ?? null)->lockForUpdate()->first();
                if (! $equipment || ! $equipment->installationTemplateSteps()->exists()) {
                    $this->fail('Select equipment with configured installation steps.');
                }
                foreach (['inst_manager_user_id' => 'Inst_Manager', 'worker_user_id' => 'Worker'] as $field => $role) {
                    $user = User::find($configuration[$field] ?? null);
                    if (! $user?->hasRole($role)) {
                        $this->fail('Select an authorized '.$role.'.');
                    }
                }
            }
            $typeId = $this->catalog->id($type.'_type');
            // Serialize the existing name generator for invoice-created orders of this type.
            DB::table('wo_type')->where('id', $typeId)->lockForUpdate()->first();
            $order = new WorkOrder;
            $order->type_id = $typeId;
            $order->status_id = $this->catalog->id('new_order_status');
            $order->customer_quickbooks_id = $invoice->customer->quickbooks_id;
            $order->date = now();
            $order->name = WorkOrderController::WOname($type === 'distribution' ? 'WD' : 'WI');
            $order->save();
            foreach ($requirements as $assetId => $quantity) {
                $order->workOrderAssets()->create(['asset_id' => $assetId, 'quantity' => $quantity]);
            }
            $installation = null;
            if ($type === 'installation') {
                $installation = $this->installations->create([
                    'work_order_id' => $order->id, 'equipment_id' => $equipment->id,
                    'customer_id' => $invoice->customer_id, 'customer_quickbooks_id' => $invoice->customer->quickbooks_id,
                    'inst_manager_user_id' => $configuration['inst_manager_user_id'],
                    'worker_user_id' => $configuration['worker_user_id'], 'status' => 'draft',
                ]);
            }
            $descriptions = $invoice->lines->filter(fn ($line) => $line->isPhysical())
                ->groupBy('asset_id')->map(fn ($rows) => $rows->pluck('description')->filter()->unique()->implode("\n"))->all();
            $invoice->update(['operation_type' => $type, 'operation_status' => 'created', 'classified_at' => now(),
                'classified_by' => $actor->id, 'work_order_id' => $order->id,
                'operation_snapshot' => ['requirements' => $requirements, 'descriptions' => $descriptions,
                    'customer_quickbooks_id' => (string) $invoice->customer->quickbooks_id]]);
            foreach (['Invoice Classified', ucfirst($type).' WorkOrder Created'] as $event) {
                $invoice->events()->create(['event' => $event, 'work_order_id' => $order->id,
                    'installation_id' => $installation?->id, 'user_id' => $actor->id, 'metadata' => ['requirements' => $requirements]]);
            }

            return $order;
        }, 3);
    }

    /** Link a validated accounting snapshot; never create or alter installation requirements. */
    public function assignToInstallation(int $workOrderId, int $invoiceId, ?User $actor): Invoice
    {
        abort_unless($actor?->hasRole(['Admin', 'Manager']) && ! $actor->hasRole('Customer'), 403);

        return DB::transaction(function () use ($workOrderId, $invoiceId, $actor) {
            $order = WorkOrder::whereKey($workOrderId)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('assignInvoice', $order);
            $installation = Installation::where('work_order_id', $order->id)->lockForUpdate()->firstOrFail();
            if ($order->invoice()->exists()) {
                $this->fail('This Installation Work Order already has an Invoice.');
            }
            $customer = Customer::whereKey($installation->customer_id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::whereKey($invoiceId)->lockForUpdate()->firstOrFail();
            if ($invoice->work_order_id || $invoice->operation_type || $invoice->classified_at) {
                $this->fail('This Invoice already belongs to an operation.');
            }
            if (! $invoice->quickbooks_id || ! $invoice->realm_id || $invoice->sync_status !== 'synced'
                || ! $invoice->last_synced_at || $invoice->sync_error !== null || $invoice->status === 'void'
                || in_array($invoice->operation_status, ['requires_review', 'sync_conflict'], true)
                || (string) ($invoice->raw_payload['Id'] ?? '') !== (string) $invoice->quickbooks_id) {
                $this->fail('Select a valid imported QuickBooks Invoice without pending synchronization errors.');
            }
            if ((int) $invoice->customer_id !== (int) $customer->id || ! $customer->quickbooks_id
                || QuickBooksReadGateway::ref($invoice->raw_payload['CustomerRef'] ?? null) !== (string) $customer->quickbooks_id
                || (string) $installation->customer_quickbooks_id !== (string) $customer->quickbooks_id
                || (string) $order->customer_quickbooks_id !== (string) $customer->quickbooks_id) {
                $this->fail('Invoice, Installation and Work Order must belong to the same QuickBooks customer.');
            }
            $filters = $invoice->metadata['operational_filters'] ?? null;
            if (! $filters || ($filters['category_filter'] ?? null) !== 'passed'
                || ($filters['unknown_items'] ?? 0) > 0 || ($filters['payment_filter'] ?? null) === 'review') {
                $this->fail('The imported Invoice categories or payment information require review.');
            }
            // Paid invoices may be linked retrospectively. This does not create an operation.
            $requirements = $this->requirements->resolve($invoice, true, $filters);
            $invoice->update(['work_order_id' => $order->id, 'operation_type' => 'installation',
                'operation_status' => 'created', 'classified_at' => now(), 'classified_by' => $actor->id,
                'operation_snapshot' => ['link_mode' => 'existing_installation', 'installation_id' => $installation->id,
                    'requirements' => $requirements, 'customer_quickbooks_id' => (string) $customer->quickbooks_id]]);
            $invoice->events()->create(['event' => 'Invoice Assigned to Existing Installation', 'work_order_id' => $order->id,
                'installation_id' => $installation->id, 'user_id' => $actor->id,
                'metadata' => ['link_mode' => 'existing_installation', 'requirements' => $requirements]]);

            return $invoice->fresh();
        }, 3);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['invoice' => $message]);
    }
}
