<?php

use App\Models\History;
use App\Models\ProductCycle;
use App\Models\User;
use App\Models\WorkOrderProduct;
use App\Services\ProductTraceabilityService;
use App\Services\ReviewInspectionService;
use App\Services\ReviewWorkOrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../Support/ProductionDatabase.php';

function simultaneousProductionWorkers(array $connection, array $actions): void
{
    $workers = [];
    $outcomes = [];
    try {
        foreach ($actions as $action) {
            $process = proc_open([PHP_BINARY, base_path('tests/Support/production-concurrency-worker.php')],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            if (! is_resource($process)) {
                throw new RuntimeException('Could not start concurrency worker.');
            }
            $workers[] = [$process, $pipes];
            fwrite($pipes[0], json_encode(compact('connection', 'action'), JSON_THROW_ON_ERROR)."\n");
            fflush($pipes[0]);
        }
        foreach ($workers as [$process, $pipes]) {
            stream_set_timeout($pipes[1], 30);
            expect(trim(fgets($pipes[1]) ?: ''))->toBe('READY');
        }
        foreach ($workers as [$process, $pipes]) {
            fwrite($pipes[0], "GO\n");
            fclose($pipes[0]);
        }
        foreach ($workers as [$process, $pipes]) {
            $outcomes[] = trim(fgets($pipes[1]) ?: '');
            fclose($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            expect(proc_close($process))->toBe(0)->and($errors)->toBe('');
        }
        sort($outcomes);
        expect($outcomes)->toBe(['DONE', 'REJECTED']);
    } finally {
        foreach ($workers as [$process, $pipes]) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }
}

it('serializes competing reservations capacity confirmation and duplicate repair fills on isolated MySQL', function () {
    $database = getenv('PRODUCTION_TEST_MYSQL_DATABASE');
    if (! $database) {
        $this->markTestSkipped('Requires an empty isolated PRODUCTION_TEST_MYSQL_DATABASE.');
    }
    expect((bool) preg_match('/^proteng_production_test_[a-zA-Z0-9_]+$/', $database))->toBeTrue();
    $connection = ['driver' => 'mysql', 'host' => getenv('PRODUCTION_TEST_MYSQL_HOST') ?: '127.0.0.1',
        'port' => getenv('PRODUCTION_TEST_MYSQL_PORT') ?: '3306', 'database' => $database,
        'username' => getenv('PRODUCTION_TEST_MYSQL_USERNAME') ?: '', 'password' => getenv('PRODUCTION_TEST_MYSQL_PASSWORD') ?: '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true];
    config(['database.default' => 'production_test', 'database.connections.production_test' => $connection,
        'production.catalog_ids' => [], 'cache.default' => 'array', 'permission.cache.store' => 'array']);
    DB::purge('production_test');
    expect(DB::connection()->getDatabaseName())->toBe($database)->and(Schema::getTables())->toBe([]);
    // Never reset, truncate, delete or reuse an existing database.
    createTraceabilityFixtures();
    phaseOneUp();
    DB::table('wo_status')->insert([['id' => 12, 'name' => 'Approved'], ['id' => 13, 'name' => 'inProcess'], ['id' => 16, 'name' => 'Ready']]);
    DB::table('work_centers')->insert([['id' => 21, 'name' => 'Assemble'], ['id' => 22, 'name' => 'Fill'], ['id' => 23, 'name' => 'Waiting'], ['id' => 24, 'name' => 'Quality']]);
    DB::table('locations')->insert([['id' => 41, 'name' => 'Production'], ['id' => 42, 'name' => 'Waiting'], ['id' => 43, 'name' => 'Quality'], ['id' => 44, 'name' => 'Stock'], ['id' => 45, 'name' => 'Recycle']]);
    DB::table('status')->insert([['id' => 53, 'name' => 'inProcess'], ['id' => 55, 'name' => 'Recycled'], ['id' => 56, 'name' => 'Ready']]);
    phaseFourUp();
    foreach (['2026_10_13_110000_add_review_location.php', '2026_10_13_120000_allow_automatic_history_events.php',
        '2026_10_13_130000_add_distribution_location.php', '2026_10_13_130100_add_distribution_selection_snapshot.php'] as $file) {
        (require database_path('migrations/'.$file))->up();
    }
    $orders = [];
    for ($i = 0; $i < 3; $i++) {
        $order = traceOrder(23);
        $order->update(['status_id' => 13]);
        DB::table('wo_ass')->insert(['work_order_id' => $order->id, 'asset_id' => 31, 'quantity' => 1]);
        $orders[] = $order;
    }
    $stock = [];
    for ($i = 0; $i < 3; $i++) {
        $stock[] = traceProduct(null, ['assambled' => true, 'location_id' => 44, 'status_id' => 56]);
    }
    simultaneousProductionWorkers($connection, [
        ['kind' => 'reserve', 'order' => $orders[0]->id, 'asset' => 31, 'serial' => $stock[0]->serial],
        ['kind' => 'reserve', 'order' => $orders[1]->id, 'asset' => 31, 'serial' => $stock[0]->serial],
    ]);
    expect(WorkOrderProduct::where('purpose', 'distribution')->where('state', 'reserved')->count())->toBe(1);
    simultaneousProductionWorkers($connection, [
        ['kind' => 'reserve', 'order' => $orders[2]->id, 'asset' => 31, 'serial' => $stock[1]->serial],
        ['kind' => 'reserve', 'order' => $orders[2]->id, 'asset' => 31, 'serial' => $stock[2]->serial],
    ]);
    expect($orders[2]->productAssociations()->where('state', 'reserved')->count())->toBe(1);
    simultaneousProductionWorkers($connection, [
        ['kind' => 'confirm', 'order' => $orders[2]->id], ['kind' => 'confirm', 'order' => $orders[2]->id],
    ]);
    expect(History::where('work_order_id', $orders[2]->id)->where('process', 'Distribution confirmed')->count())->toBe(1);
    $actor = Mockery::mock(User::class)->makePartial();
    $actor->forceFill(['id' => 1]);
    $actor->shouldReceive('hasRole')->andReturn(true);
    $origin = traceOrder();
    $product = traceProduct($origin, ['assambled' => true, 'qualified' => false, 'location_id' => 43, 'status_id' => 53]);
    $association = app(ProductTraceabilityService::class)->recordProductionOrigin($product, $origin, 17);
    $cycle = ProductCycle::create(['product_id' => $product->id, 'work_order_product_id' => $association->id,
        'cycle_number' => 1, 'source' => 'production', 'asset_id' => 31, 'fill_completed' => true,
        'quality_completed' => true, 'quality_result' => 'rejected', 'fill_weight' => '2500.0000', 'quality_weight' => '2490.0000', 'completed_at' => now()]);
    app(ProductTraceabilityService::class)->claim($association, $cycle, 1);
    $reviews = app(ReviewWorkOrderService::class);
    $review = $reviews->enqueue($cycle, $actor)->workOrder;
    $reviews->close($review->id, $actor);
    $reviews->approve($review->id, $actor);
    $reviews->start($review->id, $actor);
    app(ReviewInspectionService::class)->inspect($review->id, $product->id, $product->serial,
        ['result' => 'repaired', 'diagnosis' => 'Fuga', 'repair_description' => 'Válvula sustituida'], $actor);
    $action = ['kind' => 'fill', 'order' => $review->id, 'product' => $product->id, 'serial' => $product->serial, 'weight' => 2500];
    simultaneousProductionWorkers($connection, [$action, $action]);
    expect($product->cycles()->count())->toBe(2)
        ->and(History::where('products_id', $product->id)->where('process', 'Filled')->count())->toBe(1);
});
