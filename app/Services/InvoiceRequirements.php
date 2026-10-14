<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Validation\ValidationException;

class InvoiceRequirements
{
    public function resolve(Invoice $invoice, bool $requirePhysical = true): array
    {
        $requirements = [];
        foreach ($invoice->lines()->with('asset')->get() as $line) {
            if ($line->detail_type !== 'SalesItemLineDetail') {
                if ($line->detail_type === 'GroupLineDetail') {
                    $this->fail('Grouped items require administrative review.');
                }

                continue;
            }
            if ($line->item_type === 'Service') {
                continue;
            }
            if (! $line->isPhysical()) {
                $this->fail('Unknown or unsupported Item type. Sync again or review its mapping.');
            }
            if (! $line->asset || (string) $line->asset->quickbooks_id !== (string) $line->quickbooks_item_id) {
                $this->fail('Physical item '.$line->quickbooks_item_id.' needs an Asset mapping.');
            }
            $qty = $line->quantity;
            if (! is_numeric($qty) || $qty <= 0 || $qty != (int) $qty || $qty > 2147483647) {
                $this->fail('Physical quantities must be positive whole units.');
            }
            $id = (int) $line->asset_id;
            $requirements[$id] = ($requirements[$id] ?? 0) + (int) $qty;
            if ($requirements[$id] > 2147483647) {
                $this->fail('Quantity exceeds the supported limit.');
            }
        }
        if ($requirePhysical && ! $requirements) {
            $this->fail('At least one mapped physical product is required.');
        }
        ksort($requirements);

        return $requirements;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['invoice' => $message]);
    }
}
