<?php

use App\Models\Products;
use App\Models\WorkOrder;
use App\Models\WorkOrderProduct;
use Illuminate\Support\Facades\DB;

function phaseOneMigrations(): array
{
    return [
        '2026_10_09_100000_add_production_traceability_fields.php',
        '2026_10_09_100100_create_product_order_associations_and_cycles.php',
        '2026_10_09_100200_create_product_repairs_and_active_assignments.php',
        '2026_10_09_100300_add_traceability_context_to_history.php',
    ];
}

function phaseOneUp(): void
{
    foreach (phaseOneMigrations() as $file) {
        (require database_path('migrations/'.$file))->up();
    }
}

function phaseFourUp(): void
{
    foreach (['2026_10_13_100000_add_review_work_order_catalogs.php', '2026_10_13_100100_add_rejected_cycle_to_review_associations.php'] as $file) {
        (require database_path('migrations/'.$file))->up();
    }
}

function traceOrder(int $type = 17): WorkOrder
{
    $id = DB::table('work_orders')->insertGetId([
        'name' => 'TRACE-'.(DB::table('work_orders')->count() + 1), 'date' => '2026-10-09',
        'type_id' => $type, 'status_id' => 11, 'asset_id' => $type === 17 ? 31 : null,
        'quant' => 2, 'wc_changed_at' => '2026-10-04',
    ]);

    return WorkOrder::findOrFail($id);
}

function traceProduct(?WorkOrder $order = null, array $attributes = []): Products
{
    $id = DB::table('products')->insertGetId(array_merge([
        'serial' => 'A'.str_pad((string) (DB::table('products')->count() + 1), 7, '0', STR_PAD_LEFT).'B',
        'asset_id' => 31, 'work_order_id' => $order?->id, 'filled' => true,
        'weight' => 2500.125, 'f_weight' => 2497.125, 'qualified' => true,
        'fill_by' => 1, 'quality_by' => 1, 'fill_date' => '2026-10-01 13:15:00',
        'quality_date' => '2026-10-06 16:30:00', 'updated_at' => '2026-10-06 16:30:00',
    ], $attributes));

    return Products::findOrFail($id);
}

function traceAssociation(Products $product, WorkOrder $order, string $purpose = 'production'): WorkOrderProduct
{
    return WorkOrderProduct::create([
        'product_id' => $product->id, 'work_order_id' => $order->id, 'purpose' => $purpose,
        'state' => WorkOrderProduct::STATE_RECORDED,
    ]);
}

function setupTraceabilityDatabase(): void
{
    // A developer's cached config may retain APP_ENV; these fixtures always run in testing.
    app()->instance('env', 'testing');

    config([
        'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
        'database.connections.sqlite.url' => null, 'database.connections.sqlite.foreign_key_constraints' => true,
        'cache.default' => 'array', 'session.driver' => 'array',
    ]);
    DB::purge('sqlite');
    expect(DB::connection()->getDatabaseName())->toBe(':memory:');

    createTraceabilityFixtures();
}

// Callers must establish an empty, isolated test connection before invoking this.
function createTraceabilityFixtures(): void
{

    // Use the real pre-existing migrations on an isolated in-memory database.
    foreach ([
        '0001_01_01_000000_create_users_table.php',
        '2025_05_13_151035_create_asset_table.php',
        '2025_05_13_151138_create_statuses_table.php',
        '2025_05_13_151146_create_products_table.php',
        '2025_05_13_192238_create_work_orders_table.php',
        '2025_05_13_192332_create_work_centers_table.php',
        '2025_05_13_192630_create_werehouses_table.php',
        '2025_05_13_192635_create_locations_table.php',
        '2025_05_14_182236_add_field_to_products_table.php',
        '2025_05_16_134456_add_field_to_products_table.php',
        '2025_05_18_145022_create_wo_statu_table.php',
        '2025_05_18_145033_create_wo_type_table.php',
        '2025_05_18_182212_add_field_to_work_orders_table.php',
        '2025_05_19_134831_add_field_to_products_table.php',
        '2025_05_19_162005_add_field_to_work_orders_table.php',
        '2025_05_27_150423_add_field_to_work_orders_table.php',
        '2025_06_02_152008_create_wo_ass_table.php',
        '2025_09_09_180413_add_field_to_work_orders_table.php',
        '2025_11_26_142556_history.php',
        '2026_10_07_171107_create_serial_table.php',
        '2026_10_08_000000_add_serial_pool_constraints.php',
        '2026_10_09_000000_add_printed_to_serial_table.php',
    ] as $file) {
        (require database_path('migrations/'.$file))->up();
    }
    DB::table('users')->insert(['id' => 1, 'name' => 'Operator', 'email' => 'trace@example.test', 'password' => 'unused']);
    DB::table('asset')->insert([
        ['id' => 31, 'name' => 'Model A', 'weight' => 2500, 'weight_tolerance' => 5],
        ['id' => 32, 'name' => 'Model B', 'weight' => 2000, 'weight_tolerance' => 5],
    ]);
    // Deliberately differ from seeder IDs to detect hardcoded catalog assumptions.
    DB::table('wo_type')->insert([
        ['id' => 17, 'name' => 'Producction'], ['id' => 22, 'name' => 'Review'], ['id' => 23, 'name' => 'Distribution'],
    ]);
    DB::table('wo_status')->insert(['id' => 11, 'name' => 'New']);
}
