<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Invoice;
use App\Models\InvoiceLine;

class InvoiceRequirements
{
    public function filters(array $payload, callable $readItem): array
    {
        $total = $payload['TotalAmt'] ?? null;
        $balance = $payload['Balance'] ?? null;
        $payment = ['state' => 'unknown', 'filter' => 'review'];
        if (strtolower(trim((string) ($payload['PrivateNote'] ?? ''))) === 'voided') {
            $payment = ['state' => 'void', 'filter' => 'excluded'];
        } elseif (is_numeric($total) && is_numeric($balance) && is_finite((float) $total) && is_finite((float) $balance)
            && $total > 0 && $balance >= 0 && $balance <= $total) {
            $payment = ['state' => $balance == 0 ? 'paid' : ($balance < $total ? 'partial' : 'pending'),
                'filter' => $balance > 0 ? 'passed' : 'excluded'];
        }
        $items = [];
        $rows = is_array($payload['Line'] ?? null) ? $payload['Line'] : [];
        $uncertain = ! $rows;
        foreach ($rows as $line) {
            $ref = null;
            $type = $line['DetailType'] ?? null;
            if (in_array($type, ['DiscountLineDetail', 'SubTotalLineDetail', 'DescriptionOnly'], true)) {
                continue;
            }
            if ($type !== 'SalesItemLineDetail') {
                $uncertain = true;

                continue;
            }
            try {
                $ref = QuickBooksReadGateway::ref($line['SalesItemLineDetail']['ItemRef'] ?? null);
                if ($ref === 'SHIPPING_ITEM_ID') {
                    continue;
                }
                $ref = QuickBooksReadGateway::entityRef($ref);
                if (isset($items[$ref])) {
                    continue;
                }
                $item = $readItem($ref);
                if (QuickBooksReadGateway::entityRef($item['Id'] ?? null) !== $ref) {
                    throw new InvoiceDataException('Mismatched Item response.', ['code' => 'item_response_mismatch']);
                }
                $chain = [];
                $seen = [$ref => true];
                $node = $item;
                $membership = 'none';
                for ($depth = 0; ; $depth++) {
                    $parent = $node['ParentRef'] ?? null;
                    if ($parent === null) {
                        if (filter_var($node['SubItem'] ?? false, FILTER_VALIDATE_BOOLEAN) || str_contains($node['FullyQualifiedName'] ?? '', ':')) {
                            $membership = 'unknown';
                        }
                        break;
                    }
                    $id = QuickBooksReadGateway::entityRef($parent);
                    if ($depth >= 20 || isset($seen[$id])) {
                        $membership = 'unknown';
                        break;
                    }
                    $seen[$id] = true;
                    $node = $readItem($id);
                    if (QuickBooksReadGateway::entityRef($node['Id'] ?? null) !== $id || ($node['Type'] ?? null) !== 'Category') {
                        $membership = 'unknown';
                        break;
                    }
                    $chain[] = ['id' => $id, 'name' => $node['Name'] ?? null];
                    $membership = 'other';
                }
                if (! in_array($item['Type'] ?? null, ['Inventory', 'NonInventory', 'Service'], true)) {
                    $membership = 'unknown';
                }
                $items[$ref] = ['id' => $ref, 'type' => $item['Type'] ?? null, 'membership' => $membership, 'categories' => $chain];
            } catch (\Throwable $e) {
                // An unresolved reference/category is review, never an inferred exclusion.
                if (isset($ref) && is_string($ref) && ctype_digit($ref)) {
                    $items[$ref] = ['id' => $ref, 'type' => null, 'membership' => 'unknown', 'categories' => []];
                }
                $uncertain = true;
            }
        }
        $productIds = [];
        foreach ($items as $item) {
            foreach ($item['categories'] as $category) {
                if ($category['name'] === 'Product') {
                    $productIds[$category['id']] = true;
                }
            }
        }
        $productId = count($productIds) === 1 ? (string) array_key_first($productIds) : null;
        foreach ($items as &$item) {
            if (count($productIds) > 1) {
                $item['membership'] = 'unknown';
            } elseif ($item['membership'] !== 'unknown' && $productId !== null
                && in_array($productId, array_column($item['categories'], 'id'), true)) {
                $item['membership'] = 'product';
            }
        }
        unset($item);
        $productCount = count(array_filter($items, fn ($item) => $item['membership'] === 'product'));
        $unknownCount = count(array_filter($items, fn ($item) => $item['membership'] === 'unknown'));
        $category = $productCount ? 'passed' : ($uncertain || $unknownCount ? 'review' : 'excluded');
        $result = $payment['filter'] === 'review' || $uncertain || $unknownCount ? 'review'
            : ($payment['filter'] === 'excluded' || $category === 'excluded' ? 'excluded' : 'candidate');

        return ['payment_state' => $payment['state'], 'payment_filter' => $payment['filter'], 'category_filter' => $category,
            'product_category_id' => $productId, 'product_items' => $productCount,
            'other_items' => count($items) - $productCount - $unknownCount, 'unknown_items' => $unknownCount,
            'result' => $result, 'items' => $items];
    }

    public function resolve(Invoice $invoice, bool $requirePhysical = true, ?array $filters = null): array
    {
        $filters ??= $invoice->metadata['operational_filters'] ?? null;
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
            if ($filters !== null && $line->quickbooks_item_id !== 'SHIPPING_ITEM_ID') {
                $membership = $filters['items'][$line->quickbooks_item_id]['membership'] ?? 'unknown';
                if ($membership === 'unknown') {
                    $this->fail('unknown_item_category', 'Item category could not be verified.', $line);
                }
                if ($membership !== 'product') {
                    continue;
                }
            }
            $itemType = $filters['items'][$line->quickbooks_item_id]['type'] ?? $line->item_type;
            if (($itemType === 'Service' && $line->quickbooks_item_id !== 'SHIPPING_ITEM_ID') || ($itemType === 'Shipping' && $line->quickbooks_item_id === 'SHIPPING_ITEM_ID')) {
                continue;
            }
            if (! in_array($itemType, ['Inventory', 'NonInventory'], true) || $line->quickbooks_item_id === 'SHIPPING_ITEM_ID') {
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
