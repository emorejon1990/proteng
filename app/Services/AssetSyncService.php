<?php

namespace App\Services;

use App\Models\Asset;

class AssetSyncService
{
    public function __construct(private QuickBooksReadGateway $gateway) {}

    public function sync(): void
    {
        for ($position = 1; ; $position += 100) {
            $page = $this->gateway->page('Item', $position);
            foreach ($page as $item) {
                // Preserve legacy auto-import only for validated inventory goods.
                if (($item['Type'] ?? null) !== 'Inventory' || empty($item['Id'])) {
                    continue;
                }
                $asset = Asset::where('quickbooks_id', (string) $item['Id'])->first();
                if (! $asset) {
                    $asset = new Asset;
                    $asset->weight = 0;
                    $asset->weight_tolerance = 0;
                }
                $asset->quickbooks_id = (string) $item['Id'];
                $asset->quickbooks_item_type = $item['Type'];
                $asset->quickbooks_active = filter_var($item['Active'] ?? true, FILTER_VALIDATE_BOOLEAN);
                $asset->name = $item['Name'] ?? $item['FullyQualifiedName'] ?? 'Product';
                $asset->description = $item['Description'] ?? $asset->description;
                $asset->save();
            }
            if (count($page) < 100) {
                break;
            }
        }
    }
}
