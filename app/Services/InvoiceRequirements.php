<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Invoice;
use App\Models\InvoiceLine;

class InvoiceRequirements
{
    public function resolve(Invoice $invoice, bool $requirePhysical = true): array
    {
        $requirements = [];
        $assetCounts = [];
        foreach ($invoice->lines()->with('asset')->get() as $line) {
            if ($line->detail_type !== 'SalesItemLineDetail') {
                if ($line->detail_type === 'GroupLineDetail') {
                    $this->fail('grouped_line', 'Grouped items require administrative review.', $line);
                }
                if (! in_array($line->detail_type, ['DiscountLineDetail', 'SubTotalLineDetail', 'DescriptionOnly'], true)) {
                    $this->fail('unsupported_line', 'Unsupported invoice line requires administrative review.', $line);
                }

                continue;
            }
            if (! $line->quickbooks_item_id) {
                $this->fail('missing_item_reference', 'The sales line requires an ItemRef.', $line);
            }
            if (($line->item_type === 'Service' && $line->quickbooks_item_id !== 'SHIPPING_ITEM_ID') || ($line->item_type === 'Shipping' && $line->quickbooks_item_id === 'SHIPPING_ITEM_ID')) {
                continue;
            }
            if (! $line->isPhysical() || $line->quickbooks_item_id === 'SHIPPING_ITEM_ID') {
                $this->fail('unknown_item_type', 'Unknown or unsupported Item type. Sync again or review its mapping.', $line);
            }
            $assetCounts[$line->quickbooks_item_id] ??= Asset::where('quickbooks_id', $line->quickbooks_item_id)->count();
            if ($assetCounts[$line->quickbooks_item_id] > 1) {
                $this->fail('duplicate_asset', 'Physical item '.$line->quickbooks_item_id.' has multiple Asset mappings.', $line);
            }
            if (! $line->asset) {
                $this->fail('missing_asset', 'Physical item '.$line->quickbooks_item_id.' needs an Asset mapping.', $line);
            }
            if ((string) $line->asset->quickbooks_id !== (string) $line->quickbooks_item_id) {
                $this->fail('mismatched_asset', 'Asset does not match physical ItemRef '.$line->quickbooks_item_id.'.', $line);
            }
            $raw = $line->raw_payload;
            // Validate the original value before a decimal database cast can round it.
            $qty = array_key_exists('Qty', $raw['SalesItemLineDetail'] ?? []) ? $raw['SalesItemLineDetail']['Qty'] : $line->quantity;
            if (! is_numeric($qty) || ! is_finite((float) $qty) || $qty <= 0 || (float) $qty != floor((float) $qty)) {
                $this->fail('invalid_physical_quantity', 'Physical quantities must be positive whole units.', $line, $qty);
            }
            if ($qty > 2147483647) {
                $this->fail('physical_quantity_limit', 'Physical quantity exceeds the supported limit.', $line, $qty);
            }
            $id = (int) $line->asset_id;
            $requirements[$id] = ($requirements[$id] ?? 0) + (int) $qty;
            if ($requirements[$id] > 2147483647) {
                $this->fail('accumulated_quantity_limit', 'Quantity exceeds the supported limit.', $line, $qty);
            }
        }
        if ($requirePhysical && ! $requirements) {
            $this->fail('missing_physical_products', 'At least one mapped physical product is required.');
        }
        ksort($requirements);

        return $requirements;
    }

    private function fail(string $code, string $message, ?InvoiceLine $line = null, mixed $quantity = null): never
    {
        throw new InvoiceOperationalException([
            'code' => $code, 'message' => $message, 'rule' => $code,
            'line_index' => $line ? $line->line_number - 1 : null,
            'line_position' => $line?->line_number, 'detail_type' => $line?->detail_type,
            'item_ref' => $line?->quickbooks_item_id, 'item_type' => $line?->item_type,
            'asset_id' => $line?->asset_id, 'asset_quickbooks_id' => $line?->asset?->quickbooks_id,
            'quantity' => $quantity ?? $line?->quantity,
        ]);
    }
}
