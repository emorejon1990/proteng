<?php

namespace App\Services;

use App\Models\Installation;
use App\Models\InstalledProduct;
use App\Models\Products;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InstallationProductService
{
    public function __construct(private ProductionCatalog $catalog, private DistributionSelectionService $distribution) {}

    public function register(Installation $installation, string $serial, ?User $actor): InstalledProduct
    {
        abort_unless($actor, 403);
        $serial = strtoupper(trim($serial));
        if ($serial === '' || strlen($serial) > 255) {
            $this->fail('Enter a valid product serial.');
        }

        return DB::transaction(function () use ($installation, $serial, $actor) {
            $order = $installation->work_order_id
                ? WorkOrder::whereKey($installation->work_order_id)->lockForUpdate()->firstOrFail()
                : null;
            $current = Installation::whereKey($installation->id)->lockForUpdate()->firstOrFail();
            $gate = Gate::forUser($actor);
            abort_unless($gate->allows('update', $current)
                || ($actor->hasRole('Worker') && $gate->allows('view', $current)), 403);

            if (! $order || (int) $current->work_order_id !== (int) $order->id || ! $order->isInstallationOrder()
                || (string) $current->customer_quickbooks_id !== (string) $order->customer_quickbooks_id) {
                $this->fail('This installation requires a valid Installation Work Order.');
            }

            $matches = Products::where('serial', $serial)->orderBy('id')->limit(2)->lockForUpdate()->get();
            if ($matches->count() !== 1) {
                $this->fail('Product serial was not found or is duplicated.');
            }
            $product = $matches->first();
            $existing = $product->installedProducts()->where('installation_id', $current->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            if ($current->status !== Installation::STATUS_IN_PROGRESS
                || (int) $order->status_id !== $this->catalog->id('in_process_status')) {
                $this->fail('Only an in-progress installation can register products.');
            }
            if ($current->installedProducts()->where(fn ($query) => $query
                ->where('equipment_id', '!=', $current->equipment_id)->orWhere('customer_id', '!=', $current->customer_id))->exists()) {
                $this->fail('The installation customer or equipment has changed since a product was registered.');
            }
            if ($product->installedProducts()->lockForUpdate()->first()) {
                $this->fail('This product already has an installation record.');
            }
            if ($product->activeAssignment()->where(fn ($query) => $query
                ->whereNotNull('work_order_product_id')->orWhereNotNull('product_cycle_id'))->lockForUpdate()->first()
                || $product->orderAssociations()->whereIn('state', ['active', 'reserved'])->lockForUpdate()->first()) {
                $this->fail('This product is assigned to another operation.');
            }

            $dispatch = $this->matchingDispatch($product, $current);
            if (! $dispatch && ($product->work_order_id || (int) $product->location_id !== $this->catalog->id('stock_location')
                || ! $this->distribution->available()->whereKey($product->id)->lockForUpdate()->first())) {
                $this->fail('This product is neither available in Stock nor dispatched to this customer.');
            }

            $record = $current->installedProducts()->create([
                'customer_id' => $current->customer_id,
                'equipment_id' => $current->equipment_id,
                'work_order_id' => $order->id,
                'registered_by' => $actor->id,
                'product_id' => $product->id,
                'serial_number' => $product->serial,
                'installed_at' => now()->toDateString(),
            ]);
            $product->update([
                'location_id' => $this->catalog->id('installed_location'),
                'status_id' => $this->catalog->id('installed_product_status'),
                'work_order_id' => $order->id,
            ]);
            $product->history()->create([
                'process' => 'Installation registered',
                'description' => 'Installed serial '.$record->serial_number,
                'location' => 'Installed',
                'date' => now(),
                'user_id' => $actor->id,
                'work_order_id' => $order->id,
                'product_cycle_id' => $dispatch?->distribution_cycle_id
                    ?? $product->cycles()->reorder()->orderByDesc('cycle_number')->value('id'),
                'metadata' => [
                    'installation_id' => $current->id,
                    'installed_product_id' => $record->id,
                    'equipment_id' => $record->equipment_id,
                    'customer_id' => $record->customer_id,
                    'distribution_work_order_id' => $dispatch?->work_order_id,
                    'serial' => $record->serial_number,
                ],
            ]);

            return $record;
        }, 3);
    }

    private function matchingDispatch(Products $product, Installation $installation): ?WorkOrderProduct
    {
        if (! $installation->customer_quickbooks_id || ! $product->work_order_id
            || (int) $product->location_id !== $this->catalog->id('distribution_location')
            || (int) $product->status_id !== $this->catalog->id('ready_product_status')
            || ! $product->assambled || ! $product->filled || ! $product->qualified) {
            return null;
        }

        return $product->orderAssociations()->where('work_order_id', $product->work_order_id)
            ->where('purpose', WorkOrderProduct::PURPOSE_DISTRIBUTION)
            ->where('state', WorkOrderProduct::STATE_COMPLETED)
            ->where('distribution_serial', $product->serial)
            ->where('distribution_asset_id', $product->asset_id)
            ->whereHas('workOrder', fn ($query) => $query
                ->where('type_id', $this->catalog->id('distribution_type'))
                ->where('status_id', $this->catalog->id('ready_order_status'))
                ->where('customer_quickbooks_id', $installation->customer_quickbooks_id))
            ->first();
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['installationSerial' => $message]);
    }
}
