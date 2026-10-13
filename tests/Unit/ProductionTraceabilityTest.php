<?php

use App\Models\History;
use App\Models\ProductActiveAssignment;
use App\Models\ProductCycle;
use App\Models\ProductRepair;
use App\Models\Products;
use App\Models\WorkOrderProduct;
use App\Services\ProductionTraceabilityBackfill;
use App\Services\ProductTraceabilityService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

require_once __DIR__.'/../Support/ProductionDatabase.php';

beforeEach(fn () => setupTraceabilityDatabase());

it('adds traceability without altering existing operational data or calendar dates', function () {
    $order = traceOrder();
    $product = traceProduct($order);
    $historyId = DB::table('history')->insertGetId([
        'products_id' => $product->id, 'process' => 'Filled', 'date' => '2026-10-01 13:15:00',
        'user_id' => 1, 'location' => 'Filled Area', 'description' => 'Original event',
    ]);
    DB::table('wo_ass')->insert(['work_order_id' => $order->id, 'asset_id' => 31, 'quantity' => 2]);
    DB::table('serial')->insert(['serial' => $product->serial, 'status' => 3, 'products_id' => $product->id, 'printed' => true]);
    $oldProduct = (array) DB::table('products')->find($product->id);
    $oldHistory = (array) DB::table('history')->find($historyId);
    $oldOrder = (array) DB::table('work_orders')->find($order->id);
    $oldSerial = DB::table('serial')->first();
    $oldItems = DB::table('wo_ass')->get()->toArray();

    phaseOneUp();

    expect(array_intersect_key((array) DB::table('products')->find($product->id), $oldProduct))->toBe($oldProduct)
        ->and(array_intersect_key((array) DB::table('history')->find($historyId), $oldHistory))->toBe($oldHistory)
        ->and(array_intersect_key((array) DB::table('work_orders')->find($order->id), $oldOrder))->toBe($oldOrder)
        ->and(DB::table('serial')->first())->toEqual($oldSerial)
        ->and(DB::table('wo_ass')->get()->toArray())->toEqual($oldItems)
        ->and(DB::table('work_orders')->find($order->id)->wc_changed_at)->toBe('2026-10-04')
        ->and(Schema::getColumnType('work_orders', 'wc_changed_at'))->toBe('date')
        ->and(Schema::hasColumn('products', 'qualified'))->toBeTrue()
        ->and(Schema::hasColumn('products', 'qualifiled'))->toBeFalse()
        ->and(DB::table('wo_type')->count())->toBe(3)
        ->and(ProductCycle::count())->toBe(0);
});

it('associates existing products of multiple models with multiple orders and retains repair cycles', function () {
    phaseOneUp();
    $production = traceOrder();
    $review = traceOrder(22);
    $distribution = traceOrder(23);
    $a = traceProduct($production);
    $b = traceProduct($production, ['asset_id' => 32]);
    $origin = traceAssociation($a, $production);
    $reviewA = traceAssociation($a, $review, 'review');
    traceAssociation($b, $review, 'review');
    traceAssociation($a, $distribution, 'distribution');
    $first = ProductCycle::create([
        'product_id' => $a->id, 'work_order_product_id' => $origin->id, 'cycle_number' => 1,
        'fill_weight' => '2500.1250', 'quality_weight' => '2490.1250',
        'quality_result' => 'rejected', 'completed_at' => '2026-10-06 16:30:00',
    ]);
    $repair = ProductRepair::create([
        'product_id' => $a->id, 'work_order_product_id' => $reviewA->id, 'rejected_cycle_id' => $first->id,
        'diagnosis' => 'Valve leak', 'repair_description' => 'Valve adjusted',
        'result' => 'repaired', 'reviewed_by' => 1, 'reviewed_at' => now(),
    ]);
    $second = ProductCycle::create([
        'product_id' => $a->id, 'work_order_product_id' => $reviewA->id, 'cycle_number' => 2,
        'source' => 'repair', 'product_repair_id' => $repair->id, 'fill_weight' => '2502.1250',
        'waiting_started_on' => '2026-10-09', 'quality_available_on' => '2026-10-14',
    ]);
    $event = History::create([
        'products_id' => $a->id, 'process' => 'Repair', 'date' => now(), 'user_id' => 1,
        'location' => 'Review', 'description' => 'Valve adjusted', 'work_order_id' => $review->id,
        'work_order_product_id' => $reviewA->id, 'product_cycle_id' => $first->id, 'product_repair_id' => $repair->id,
        'metadata' => ['source' => 'review'],
    ]);

    expect($a->orderAssociations()->count())->toBe(3)
        ->and($review->productAssociations()->count())->toBe(2)
        ->and($a->cycles()->pluck('cycle_number')->all())->toBe([1, 2])
        ->and($first->fresh()->fill_weight)->toBe('2500.1250')
        ->and($repair->newCycle->id)->toBe($second->id)
        ->and($second->repair->id)->toBe($repair->id)
        ->and($event->cycle->id)->toBe($first->id)
        ->and($event->repair->id)->toBe($repair->id)
        ->and($event->association->id)->toBe($reviewA->id)
        ->and($event->workOrder->id)->toBe($review->id)
        ->and($second->quality_available_on->format('Y-m-d'))->toBe('2026-10-14')
        ->and($a->fresh()->serial)->toBe($a->serial)
        ->and(Products::count())->toBe(2)
        ->and(DB::table('serial')->count())->toBe(0);
});

it('enforces unique associations and cycle numbers in the database', function () {
    phaseOneUp();
    $order = traceOrder();
    $product = traceProduct($order);
    $association = traceAssociation($product, $order);
    expect(fn () => traceAssociation($product, $order))->toThrow(QueryException::class);
    ProductCycle::create(['product_id' => $product->id, 'work_order_product_id' => $association->id, 'cycle_number' => 1]);
    expect(fn () => ProductCycle::create(['product_id' => $product->id, 'cycle_number' => 1]))->toThrow(QueryException::class);
    expect(WorkOrderProduct::count())->toBe(1)->and(ProductCycle::count())->toBe(1);
});

it('rejects cross-product references for cycles repairs assignments and history', function () {
    phaseOneUp();
    $order = traceOrder();
    $a = traceProduct($order);
    $b = traceProduct($order);
    $associationA = traceAssociation($a, $order);
    $associationB = traceAssociation($b, $order);
    $cycleA = ProductCycle::create(['product_id' => $a->id, 'work_order_product_id' => $associationA->id, 'cycle_number' => 1]);
    expect(fn () => ProductCycle::create(['product_id' => $b->id, 'work_order_product_id' => $associationA->id, 'cycle_number' => 1]))->toThrow(QueryException::class);
    expect(fn () => ProductRepair::create([
        'product_id' => $b->id, 'work_order_product_id' => $associationB->id, 'rejected_cycle_id' => $cycleA->id,
    ]))->toThrow(QueryException::class);
    expect(fn () => ProductActiveAssignment::create(['product_id' => $b->id, 'work_order_product_id' => $associationA->id]))->toThrow(QueryException::class);
    expect(fn () => History::create([
        'products_id' => $b->id, 'process' => 'Quality', 'date' => now(), 'user_id' => 1,
        'location' => 'Quality', 'description' => 'Invalid context', 'product_cycle_id' => $cycleA->id,
    ]))->toThrow(QueryException::class);
});

it('retains verified production origin when the legacy operational relation is cleared', function () {
    phaseOneUp();
    $order = traceOrder();
    $other = traceOrder();
    $product = traceProduct($order);
    $service = new ProductTraceabilityService;
    $association = $service->recordProductionOrigin($product, $order, 17);
    $same = $service->recordProductionOrigin($product, $order, 17);
    $product = $product->fresh();
    $product->update(['work_order_id' => null]);

    expect($same->id)->toBe($association->id)
        ->and($product->fresh()->productionWorkOrder->id)->toBe($order->id)
        ->and($order->originalProducts()->count())->toBe(1)
        ->and($order->products()->count())->toBe(0);
    expect(fn () => $product->update(['production_work_order_id' => $other->id]))->toThrow(LogicException::class);
    expect(fn () => DB::table('work_orders')->where('id', $order->id)->delete())->toThrow(QueryException::class);
    expect(fn () => $service->recordProductionOrigin(traceProduct($other), $order, 17))->toThrow(ValidationException::class);
    expect(fn () => $service->recordProductionOrigin(traceProduct($order), $order, 1))->toThrow(ValidationException::class);
});

it('serializes assignment ownership and prevents stale release or a second operative cycle', function () {
    phaseOneUp();
    $product = traceProduct();
    $a = traceAssociation($product, traceOrder(22), 'review');
    $b = traceAssociation($product, traceOrder(23), 'distribution');
    $cycle = ProductCycle::create(['product_id' => $product->id, 'cycle_number' => 1]);
    $nextCycle = ProductCycle::create(['product_id' => $product->id, 'cycle_number' => 2]);
    $service = new ProductTraceabilityService;
    $service->claim($a, $cycle, 1);
    $service->claim($a, $cycle, 1);
    expect(ProductActiveAssignment::count())->toBe(1)
        ->and($product->activeAssignment->association->id)->toBe($a->id);
    expect(fn () => $service->claim($b))->toThrow(ValidationException::class);
    expect(fn () => $service->claim($a, $nextCycle))->toThrow(ValidationException::class);
    expect(fn () => $service->release($b))->toThrow(ValidationException::class);
    $service->release($a);
    $service->release($a);
    $service->claim($b);
    expect($product->fresh()->activeAssignment->association->id)->toBe($b->id)
        ->and($product->orderAssociations()->count())->toBe(2);
});

it('refuses a cycle of another product without leaving a partial claim', function () {
    phaseOneUp();
    $a = traceProduct();
    $b = traceProduct();
    $association = traceAssociation($a, traceOrder(22), 'review');
    $cycle = ProductCycle::create(['product_id' => $b->id, 'cycle_number' => 1]);
    expect(fn () => (new ProductTraceabilityService)->claim($association, $cycle))->toThrow(ValidationException::class);
    expect(ProductActiveAssignment::count())->toBe(0);
});

it('retains completed cycle and repair records even when a stale model tries to overwrite them', function () {
    phaseOneUp();
    $product = traceProduct();
    $association = traceAssociation($product, traceOrder(22), 'review');
    $cycle = ProductCycle::create(['product_id' => $product->id, 'cycle_number' => 1, 'fill_weight' => 2500]);
    $stale = $cycle->fresh();
    $cycle->update(['quality_completed' => true, 'quality_result' => 'approved', 'completed_at' => now()]);
    expect(fn () => $stale->update(['fill_weight' => 2490]))->toThrow(LogicException::class);
    expect(fn () => $cycle->delete())->toThrow(LogicException::class);
    expect(fn () => $association->delete())->toThrow(LogicException::class);
    $repair = ProductRepair::create(['product_id' => $product->id, 'work_order_product_id' => $association->id]);
    $staleRepair = $repair->fresh();
    $repair->update(['result' => 'recycled', 'recycling_reason' => 'Damaged tube', 'reviewed_at' => now()]);
    expect(fn () => $staleRepair->update(['recycling_reason' => 'Overwrite']))->toThrow(LogicException::class);
    expect(fn () => $repair->delete())->toThrow(LogicException::class);
    expect($cycle->fresh()->fill_weight)->toBe('2500.0000');
});

it('diagnoses legacy data before migrations without changing any record', function () {
    $order = traceOrder();
    $product = traceProduct($order);
    traceProduct(null, ['serial' => null, 'qualified' => false, 'quality_date' => null]);
    $before = DB::table('products')->get()->toArray();
    $report = (new ProductionTraceabilityBackfill)->run(17);
    expect($report['origins_identified'])->toBe(1)
        ->and($report['origins_unknown'])->toBe(1)
        ->and($report['missing_serials'])->toBe(1)
        ->and($report['cycles_to_import'])->toBe(2)
        ->and($report['cycles_written'])->toBe(0)
        ->and(DB::table('products')->get()->toArray())->toEqual($before)
        ->and(Schema::hasTable('product_cycles'))->toBeFalse();
    expect(fn () => (new ProductionTraceabilityBackfill)->run(17, true))->toThrow(RuntimeException::class);
    expect(fn () => (new ProductionTraceabilityBackfill)->run(1))->toThrow(InvalidArgumentException::class);
});

it('imports legacy snapshots idempotently without inventing origins rejections or waiting dates', function () {
    $order = traceOrder();
    $known = traceProduct($order);
    $unknown = traceProduct(null, ['qualified' => false]);
    $old = (array) DB::table('products')->find($known->id);
    phaseOneUp();
    $service = new ProductionTraceabilityBackfill;
    $report = $service->run(17, true);
    $repeat = $service->run(17, true);
    $cycle = $known->cycles()->first();
    expect($report['origins_written'])->toBe(1)
        ->and($report['cycles_written'])->toBe(2)
        ->and($repeat['cycles_written'])->toBe(0)
        ->and($repeat['associations_written'])->toBe(0)
        ->and($known->fresh()->production_work_order_id)->toBe($order->id)
        ->and($unknown->fresh()->production_work_order_id)->toBeNull()
        ->and($cycle->legacy_snapshot['weight'])->toBe($old['weight'])
        ->and($cycle->fill_weight)->toBe('2500.1250')
        ->and($cycle->quality_result)->toBe('approved')
        ->and($cycle->waiting_started_on)->toBeNull()
        ->and($unknown->cycles()->first()->quality_result)->toBeNull()
        ->and($unknown->cycles()->first()->quality_completed)->toBeNull()
        ->and(array_intersect_key((array) DB::table('products')->find($known->id), $old))->toBe($old)
        ->and(ProductActiveAssignment::count())->toBe(0);
    DB::table('products')->where('id', $known->id)->update(['weight' => 2600]);
    $service->run(17, true);
    expect($cycle->fresh()->fill_weight)->toBe('2500.1250');
});

it('reports ambiguous quality flags duplicate serials and conflicting origins without overwriting them', function () {
    phaseOneUp();
    Schema::table('products', function (Blueprint $table) {
        $table->boolean('qualifiled')->nullable();
    });
    $production = traceOrder();
    $other = traceOrder();
    $a = traceProduct($production, ['qualified' => true, 'qualifiled' => false]);
    traceProduct(null, ['serial' => $a->serial]);
    $conflict = traceProduct($other, ['production_work_order_id' => $production->id]);
    $report = (new ProductionTraceabilityBackfill)->run(17, true);
    expect($report['ambiguous_quality_flags'])->toBe(1)
        ->and($report['duplicate_serial_groups'])->toBe(1)
        ->and($report['origin_conflicts'])->toBe(1)
        ->and($a->cycles()->first()->quality_result)->toBeNull()
        ->and($a->cycles()->first()->legacy_snapshot['qualifiled'])->toBe(0)
        ->and($conflict->cycles()->count())->toBe(0)
        ->and($conflict->fresh()->production_work_order_id)->toBe($production->id);
});

it('rolls back the entire import for a product when its legacy references are invalid', function () {
    $order = traceOrder();
    Schema::disableForeignKeyConstraints();
    $product = traceProduct($order, ['fill_by' => 999]);
    Schema::enableForeignKeyConstraints();
    phaseOneUp();
    expect(fn () => (new ProductionTraceabilityBackfill)->run(17, true))->toThrow(QueryException::class);
    expect($product->fresh()->production_work_order_id)->toBeNull()
        ->and(WorkOrderProduct::count())->toBe(0)
        ->and(ProductCycle::count())->toBe(0);
});

it('supports empty rollback while preserving the original tables and their data', function () {
    $order = traceOrder();
    $product = traceProduct($order);
    phaseOneUp();
    foreach (array_reverse(phaseOneMigrations()) as $file) {
        (require database_path('migrations/'.$file))->down();
    }
    expect(Schema::hasTable('product_cycles'))->toBeFalse()
        ->and(Schema::hasColumn('products', 'production_work_order_id'))->toBeFalse()
        ->and($product->fresh()->serial)->toBe($product->serial)
        ->and($order->fresh()->wc_changed_at)->toBe('2026-10-04');
});

it('blocks rollback that would discard imported history', function () {
    phaseOneUp();
    $product = traceProduct(traceOrder());
    (new ProductionTraceabilityBackfill)->run(17, true);
    $migration = require database_path('migrations/'.phaseOneMigrations()[1]);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect(ProductCycle::count())->toBe(1)->and($product->fresh()->production_work_order_id)->not->toBeNull();
});

it('requires an explicit catalog ID and defaults the command to read only', function () {
    traceProduct(traceOrder());
    $this->artisan('production:backfill-traceability')->assertFailed();
    $this->artisan('production:backfill-traceability', ['--production-type' => 17])->assertSuccessful();
    expect(Schema::hasTable('product_cycles'))->toBeFalse()->and(Products::count())->toBe(1);
});
