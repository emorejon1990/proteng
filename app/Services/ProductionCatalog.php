<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionCatalog
{
    private const ENTRIES = [
        'production_type' => ['wo_type', ['production', 'producction']],
        'distribution_type' => ['wo_type', ['distribution']],
        'distribution_location' => ['locations', ['distribution']],
        'assemble_center' => ['work_centers', ['assemble', 'assembly']],
        'production_location' => ['locations', ['production']],
        'review_type' => ['wo_type', ['review']],
        'review_center' => ['work_centers', ['review']],
        'review_location' => ['locations', ['review']],
        'recycle_location' => ['locations', ['recycle']],
        'recycled_product_status' => ['status', ['recycled']],
        'new_order_status' => ['wo_status', ['new']],
        'approved_order_status' => ['wo_status', ['approved']],
        'in_process_status' => ['wo_status', ['inprocess', 'inprogress']],
        'filling_center' => ['work_centers', ['fill', 'filling']],
        'waiting_center' => ['work_centers', ['waiting']],
        'waiting_location' => ['locations', ['waiting']],
        'quality_center' => ['work_centers', ['quality']],
        'quality_location' => ['locations', ['quality']],
        'stock_location' => ['locations', ['stock']],
        'ready_order_status' => ['wo_status', ['ready']],
        'ready_product_status' => ['status', ['ready']],
        'in_process_product_status' => ['status', ['inprocess', 'inprogress']],
    ];

    public function id(string $key): int
    {
        [$table, $names] = self::ENTRIES[$key];
        $configured = config('production.catalog_ids.'.$key);
        if ($configured !== null && $configured !== '') {
            $id = filter_var($configured, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false && DB::table($table)->where('id', $id)->exists()) {
                return $id;
            }
        } else {
            $matches = DB::table($table)->whereIn(DB::raw('LOWER(TRIM(name))'), $names)->pluck('id');
            if ($matches->count() === 1) {
                return (int) $matches->first();
            }
        }

        throw ValidationException::withMessages([
            'workOrder' => 'La estación no está disponible: un catálogo requiere revisión del administrador.',
        ]);
    }
}
