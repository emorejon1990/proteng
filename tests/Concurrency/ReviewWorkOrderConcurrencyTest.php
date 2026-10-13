<?php

use App\Models\ProductCycle;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderProduct;
use App\Services\ProductTraceabilityService;
use App\Services\ReviewWorkOrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../Support/ProductionDatabase.php';

function simultaneousReviewWorkers(array $connection, array $batches): void
{
    $workers = [];
    try {
        foreach ($batches as $cycles) {
            $process = proc_open([PHP_BINARY, base_path('tests/Support/review-concurrency-worker.php')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            if (! is_resource($process)) {
                throw new RuntimeException('Could not start a Review test worker.');
            }
            $workers[] = [$process, $pipes];
            fwrite($pipes[0], json_encode(['connection' => $connection, 'cycles' => $cycles], JSON_THROW_ON_ERROR)."\n");
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
            expect(trim(fgets($pipes[1]) ?: ''))->toBe('DONE');
            fclose($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            expect(proc_close($process))->toBe(0)->and($errors)->toBe('');
        }
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

it('serializes concurrent Review creation the fiftieth slot and repeated rejection allocation on MySQL', function () {
    $database = getenv('REVIEW_TEST_MYSQL_DATABASE');
    if (! $database) {
        $this->markTestSkipped('Requires an empty isolated MySQL database explicitly supplied in REVIEW_TEST_MYSQL_DATABASE.');
    }
    expect((bool) preg_match('/^proteng_review_test_[a-zA-Z0-9_]+$/', $database))->toBeTrue();
    $connection = [
        'driver' => 'mysql', 'host' => getenv('REVIEW_TEST_MYSQL_HOST') ?: '127.0.0.1',
        'port' => getenv('REVIEW_TEST_MYSQL_PORT') ?: '3306', 'database' => $database,
        'username' => getenv('REVIEW_TEST_MYSQL_USERNAME') ?: '', 'password' => getenv('REVIEW_TEST_MYSQL_PASSWORD') ?: '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
    ];
    config(['database.default' => 'review_test', 'database.connections.review_test' => $connection,
        'production.catalog_ids' => [], 'cache.default' => 'array', 'permission.cache.store' => 'array']);
    DB::purge('review_test');
    expect(DB::connection()->getDatabaseName())->toBe($database)->and(Schema::getTables())->toBe([]);
    // Never reset, truncate, clean up or reuse a database containing any tables.
    createTraceabilityFixtures();
    phaseOneUp();
    DB::table('wo_status')->insert([['id' => 12, 'name' => 'Approved'], ['id' => 13, 'name' => 'inProcess']]);
    DB::table('locations')->insert(['id' => 43, 'name' => 'Quality']);
    phaseFourUp();
    $origin = traceOrder();
    $cycles = [];
    for ($i = 0; $i < 51; $i++) {
        $product = traceProduct($origin, ['qualified' => false, 'location_id' => 43]);
        $association = app(ProductTraceabilityService::class)->recordProductionOrigin($product, $origin, 17);
        $cycle = ProductCycle::create([
            'product_id' => $product->id, 'work_order_product_id' => $association->id, 'cycle_number' => 1,
            'fill_completed' => true, 'fill_weight' => '2500.0000', 'quality_weight' => '2490.0000',
            'quality_completed' => true, 'quality_result' => 'rejected', 'completed_at' => now(),
        ]);
        app(ProductTraceabilityService::class)->claim($association, $cycle, 1);
        $cycles[] = $cycle;
    }
    simultaneousReviewWorkers($connection, [[$cycles[0]->id], [$cycles[1]->id]]);
    expect(WorkOrder::where('type_id', 22)->count())->toBe(1);
    $actor = Mockery::mock(User::class)->makePartial();
    $actor->forceFill(['id' => 1]);
    $actor->shouldReceive('hasRole')->with(['Admin', 'Manager', 'Worker'])->andReturn(true);
    for ($i = 2; $i < 49; $i++) {
        app(ReviewWorkOrderService::class)->enqueue($cycles[$i], $actor);
    }
    simultaneousReviewWorkers($connection, [[$cycles[49]->id, $cycles[49]->id], [$cycles[50]->id, $cycles[49]->id]]);
    $orders = WorkOrder::where('type_id', 22)->orderBy('id')->get();
    expect($orders)->toHaveCount(2)->and($orders[0]->quant)->toBe(50)
        ->and($orders[0]->review_receiving_open)->toBeFalse()->and($orders[1]->quant)->toBe(1)
        ->and(WorkOrderProduct::where('purpose', 'review')->count())->toBe(51);
});
