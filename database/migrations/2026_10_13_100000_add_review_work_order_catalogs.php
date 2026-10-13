<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            foreach (['wo_type', 'work_centers'] as $table) {
                $existing = DB::table($table)->whereRaw('LOWER(TRIM(name)) = ?', ['review'])->count();
                if ($existing > 1) {
                    throw new RuntimeException("Review is ambiguous in {$table}; reconcile the catalog before migrating.");
                }
                if ($existing === 0) {
                    DB::table($table)->insert(['name' => 'Review', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        // Never remove adopted catalog rows or invalidate historical orders.
        throw new RuntimeException('Review catalogs are retained. Rollback requires an explicit data-preserving plan.');
    }
};
