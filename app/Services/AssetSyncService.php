<?php

namespace App\Services;

use App\Models\Asset;
use QuickBooksOnline\API\Facades\Item as QBItem;

class AssetSyncService
{
    public function __construct(
        protected QuickBooksService $qb
    ) {}

    public function sync(): void
    {
        $ds = $this->qb->ds();
        $items = $ds->Query("SELECT * FROM Item") ?? [];
        $qbIds = collect($items)
            ->map(fn ($qbItem) => (string) ($qbItem->Id ?? ''))
            ->filter()
            ->values()
            ->all();

        foreach ($items as $qbItem) {
            $asset = Asset::firstOrNew([
                'quickbooks_id' => (string) $qbItem->Id,
            ]);

            $asset->name = $qbItem->Name ?? $qbItem->FullyQualifiedName ?? 'Producto';
            $asset->description = $qbItem->Description ?? $qbItem->PurchaseDesc ?? null;

            if (! $asset->exists) {
                $asset->weight = 0;
                $asset->weight_tolerance = 0;
            }

            $asset->save();
        }

        if (count($qbIds) === 0) {
            Asset::whereNotNull('quickbooks_id')->delete();
        } else {
            Asset::whereNotNull('quickbooks_id')
                ->whereNotIn('quickbooks_id', $qbIds)
                ->delete();
        }
    }
}
