<?php

namespace App\Services;

use App\Models\ProductCycle;
use App\Models\WorkOrderProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

class ProductionTraceabilityBackfill
{
    /**
     * Import only verifiable current values. Never infer detached production orders,
     * previous cycles, rejections or waiting dates from catalog IDs or descriptions.
     */
    public function run(int $productionTypeId, bool $apply = false): array
    {
        if ($productionTypeId < 1 || ! DB::table('wo_type')->where('id', $productionTypeId)->exists()) {
            throw new InvalidArgumentException('Indica un ID existente y verificado del tipo Production.');
        }

        $hasOrigins = Schema::hasColumn('products', 'production_work_order_id');
        $hasAssociations = Schema::hasTable('work_order_products');
        $hasCycles = Schema::hasTable('product_cycles');
        if ($apply && (! $hasOrigins || ! $hasAssociations || ! $hasCycles)) {
            throw new RuntimeException('Faltan las migraciones de trazabilidad. No se modificaron productos.');
        }

        $report = [
            'products_scanned' => 0,
            'origins_identified' => 0,
            'origins_unknown' => 0,
            'origin_conflicts' => 0,
            'cycles_to_import' => 0,
            'cycles_already_present' => 0,
            'ambiguous_quality_flags' => 0,
            'missing_serials' => 0,
            'duplicate_serial_groups' => DB::query()->fromSub(
                DB::table('products')->select('serial')->whereNotNull('serial')
                    ->whereRaw("TRIM(serial) <> ''")->groupBy('serial')->havingRaw('COUNT(*) > 1'),
                'duplicate_serials'
            )->count(),
            'origins_written' => 0,
            'associations_written' => 0,
            'cycles_written' => 0,
        ];

        DB::table('products')->orderBy('id')->chunkById(100, function ($products) use (
            &$report, $productionTypeId, $apply, $hasOrigins, $hasAssociations, $hasCycles
        ) {
            foreach ($products as $product) {
                if ($apply) {
                    DB::transaction(function () use ($product, $productionTypeId, &$report) {
                        // All imports for a product serialize on its identity, including first imports.
                        $locked = DB::table('products')->where('id', $product->id)->lockForUpdate()->first();
                        if ($locked) {
                            $this->inspect($locked, $productionTypeId, true, true, true, true, $report);
                        }
                    });
                } else {
                    $this->inspect($product, $productionTypeId, false, $hasOrigins, $hasAssociations, $hasCycles, $report);
                }
            }
        });

        return $report;
    }

    private function inspect(object $product, int $productionTypeId, bool $apply, bool $hasOrigins,
        bool $hasAssociations, bool $hasCycles, array &$report): void
    {
        $report['products_scanned']++;
        if (trim((string) ($product->serial ?? '')) === '') {
            $report['missing_serials']++;
        }

        $originalId = $hasOrigins ? ($product->production_work_order_id ?? null) : null;
        $candidateId = $originalId ?? $product->work_order_id ?? null;
        $order = $candidateId === null ? null : DB::table('work_orders')->where('id', $candidateId)->first();
        $verifiedOrder = $order && (int) $order->type_id === $productionTypeId;
        $conflict = $originalId !== null && ! $verifiedOrder;
        if ($originalId !== null && $product->work_order_id !== null
            && (int) $originalId !== (int) $product->work_order_id) {
            $conflict = $conflict || DB::table('work_orders')->where('id', $product->work_order_id)
                ->where('type_id', $productionTypeId)->exists();
        }
        if ($conflict) {
            $report['origin_conflicts']++;

            return;
        }

        $association = null;
        if ($verifiedOrder) {
            $report['origins_identified']++;
            if ($apply && $originalId === null) {
                // Retain products.updated_at and every existing operational field.
                DB::table('products')->where('id', $product->id)->update(['production_work_order_id' => $order->id]);
                $report['origins_written']++;
            }
            if ($hasAssociations) {
                $association = WorkOrderProduct::where('product_id', $product->id)
                    ->where('work_order_id', $order->id)->where('purpose', WorkOrderProduct::PURPOSE_PRODUCTION)->first();
                if ($apply && ! $association) {
                    $association = WorkOrderProduct::create([
                        'product_id' => $product->id,
                        'work_order_id' => $order->id,
                        'purpose' => WorkOrderProduct::PURPOSE_PRODUCTION,
                        'state' => WorkOrderProduct::STATE_RECORDED,
                    ]);
                    $report['associations_written']++;
                }
            }
        } else {
            $report['origins_unknown']++;
        }

        $qualified = $product->qualified ?? null;
        $ambiguousQuality = property_exists($product, 'qualifiled') && $product->qualifiled !== null
            && ($qualified === null || (bool) $qualified !== (bool) $product->qualifiled);
        if ($ambiguousQuality) {
            $report['ambiguous_quality_flags']++;
        }
        if ($hasCycles && ProductCycle::where('product_id', $product->id)->exists()) {
            $report['cycles_already_present']++;

            return;
        }
        $report['cycles_to_import']++;
        if (! $apply) {
            return;
        }

        // A false legacy checkbox with a date cannot reliably establish rejection.
        $approved = ! $ambiguousQuality && $qualified !== null && (bool) $qualified;
        $qualityCompleted = $approved ? true : (($qualified !== null && empty($product->quality_date) && ! $ambiguousQuality) ? false : null);
        $snapshotFields = [
            'serial', 'asset_id', 'work_order_id', 'production_work_order_id', 'location_id', 'status_id',
            'weight', 'f_weight', 'assambled', 'assambly_by', 'assambly_date', 'filled', 'fill_by',
            'fill_date', 'qualified', 'qualifiled', 'quality_by', 'quality_date', 'created_at', 'updated_at',
        ];
        $snapshot = array_intersect_key((array) $product, array_flip($snapshotFields));

        ProductCycle::create([
            'product_id' => $product->id,
            'work_order_product_id' => $association?->id,
            'cycle_number' => 1,
            'source' => ProductCycle::SOURCE_LEGACY,
            'asset_id' => $product->asset_id ?? null,
            'fill_weight' => $product->weight ?? null,
            'quality_weight' => $product->f_weight ?? null,
            'fill_completed' => $product->filled ?? null,
            'quality_completed' => $qualityCompleted,
            'quality_result' => $approved ? ProductCycle::RESULT_APPROVED : null,
            'fill_by' => $product->fill_by ?? null,
            'quality_by' => $product->quality_by ?? null,
            'fill_date' => $product->fill_date ?? null,
            'quality_date' => $product->quality_date ?? null,
            'completed_at' => $approved ? ($product->quality_date ?? null) : null,
            'legacy_snapshot' => $snapshot,
        ]);
        $report['cycles_written']++;
    }
}
