<?php

namespace App\Services;

use App\Models\Asset;

class AssetSyncService
{
    public function __construct(
        protected QuickBooksService $qb
    ) {}

    public function sync(): void
    {
        $ds = $this->qb->ds();
        $items = $ds->Query("SELECT * FROM Item") ?? [];

        foreach ($items as $qbItem) {
            $quickbooksId = (string) ($qbItem->Id ?? '');

            if ($quickbooksId === '' || Asset::where('quickbooks_id', $quickbooksId)->exists()) {
                continue;
            }

            $asset = new Asset;
            $asset->quickbooks_id = $quickbooksId;

            $asset->name = $qbItem->Name ?? $qbItem->FullyQualifiedName ?? 'Producto';
            $asset->description = $qbItem->Description ?? $qbItem->PurchaseDesc ?? null;

            if (! $asset->exists) {
                $asset->weight = 0;
                $asset->weight_tolerance = 0;
            }
            
            $asset->weight = 0;
            $asset->weight_tolerance = 0;

            $asset->save();
        }
    }
}
