<?php

use App\Livewire\WorkOrderFillingEditor;
use App\Livewire\WorkOrderQualityEditor;
use App\Models\History;
use App\Models\ProductCycle;
use App\Models\ProductRepair;
use App\Models\Products;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderProduct;
use App\Services\ProductionFillingService;
use App\Services\ProductionQualityService;
use App\Services\ProductionWaitingService;
use App\Services\ProductTraceabilityService;
use App\Services\ReviewInspectionService;
use App\Services\ReviewWorkOrderService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../Support/ProductionDatabase.php';

function cycleOperator(bool $allowed = true): User
{
    $user = Mockery::mock(User::class)->makePartial();
    $user->forceFill(['id' => 1, 'name' => 'Repair operator']);
    $user->shouldReceive('hasRole')->andReturn($allowed);

    return $user;
}

function cycleInspection(WorkOrder $order, Products $product, string $result = 'repaired'): void
{
    app(ReviewInspectionService::class)->inspect($order->id, $product->id, $product->serial, [
        'result' => $result, 'diagnosis' => 'Fuga', 'repair_description' => 'Válvula sustituida',
        'recycling_reason' => 'Fisura estructural',
    ], cycleOperator(), $result === 'recycled');
}

function cycleReview(array $results = ['repaired', 'repaired']): array
{
    $reviews = app(ReviewWorkOrderService::class);
    $products = [];
    foreach ($results as $result) {
        $origin = traceOrder();
        $product = traceProduct($origin, [
            'assambled' => true, 'qualified' => false, 'location_id' => 43, 'status_id' => 53,
            'weight' => 2500, 'f_weight' => 2490,
        ]);
        $association = (new ProductTraceabilityService)->recordProductionOrigin($product, $origin, 17);
        $cycle = ProductCycle::create([
            'product_id' => $product->id, 'work_order_product_id' => $association->id,
            'cycle_number' => 1, 'source' => 'production', 'asset_id' => 31,
            'fill_weight' => '2500.0000', 'fill_completed' => true,
            'quality_weight' => '2490.0000', 'quality_completed' => true, 'quality_result' => 'rejected',
            'fill_date' => '2026-10-01', 'quality_by' => 1, 'quality_date' => now(), 'completed_at' => now(),
        ]);
        (new ProductTraceabilityService)->claim($association, $cycle, 1);
        $member = $reviews->enqueue($cycle, cycleOperator());
        $products[] = $product->fresh();
    }
    $reviews->close($member->work_order_id, cycleOperator());
    $reviews->approve($member->work_order_id, cycleOperator());
    $order = $reviews->start($member->work_order_id, cycleOperator());
    foreach ($products as $i => $product) {
        cycleInspection($order, $product, $results[$i]);
    }

    return [$order->fresh(), $products];
}

beforeEach(function () {
    setupTraceabilityDatabase();
    phaseOneUp();
    config(['production.catalog_ids' => []]);
    DB::table('wo_status')->insert([['id' => 12, 'name' => 'Approved'], ['id' => 13, 'name' => 'inProcess'], ['id' => 16, 'name' => 'Ready']]);
    DB::table('work_centers')->insert([['id' => 22, 'name' => 'Fill'], ['id' => 23, 'name' => 'Waiting'], ['id' => 24, 'name' => 'Quality']]);
    DB::table('locations')->insert([['id' => 42, 'name' => 'Waiting'], ['id' => 43, 'name' => 'Quality'], ['id' => 44, 'name' => 'Stock'], ['id' => 45, 'name' => 'Recycle']]);
    DB::table('status')->insert([['id' => 53, 'name' => 'inProcess'], ['id' => 55, 'name' => 'Recycled'], ['id' => 56, 'name' => 'Ready']]);
    phaseFourUp();
    (require database_path('migrations/2026_10_13_110000_add_review_location.php'))->up();
    (require database_path('migrations/2026_10_13_120000_allow_automatic_history_events.php'))->up();
    $this->travelTo(Carbon::parse('2026-10-13 23:59:00'));
});

it('starts a separate repair cycle only when filling is saved and retains the previous rejection', function () {
    [$order, $products] = cycleReview();
    $product = $products[0]->fresh();
    $original = $product->cycles()->first()->getRawOriginal();
    $snapshot = $product->getRawOriginal();
    $service = app(ProductionFillingService::class);
    expect($service->orders()->whereKey($order->id)->exists())->toBeTrue()
        ->and($service->pendingProducts($order)->count())->toBe(2);
    $service->select($order->id, $product->serial, cycleOperator());
    expect(ProductCycle::count())->toBe(2)->and($product->fresh()->getRawOriginal())->toBe($snapshot);
    $result = $service->fill($order->id, $product->id, $product->serial, '2503', cycleOperator());
    $new = $product->cycles()->reorder()->orderByDesc('cycle_number')->first();
    expect($result['completed'])->toBeFalse()->and($new->cycle_number)->toBe(2)
        ->and($new->source)->toBe('repair')->and($new->product_repair_id)->not->toBeNull()
        ->and($new->fill_weight)->toBe('2503.0000')->and($new->quality_weight)->toBeNull()
        ->and($new->legacy_snapshot['f_weight'])->toEqual($snapshot['f_weight'])
        ->and($new->waiting_started_on)->toBeNull()
        ->and($product->fresh()->f_weight)->toBeNull()->and($product->fresh()->quality_date)->toBeNull()
        ->and($product->fresh()->production_work_order_id)->toBe($product->production_work_order_id)
        ->and($product->cycles()->first()->getRawOriginal())->toBe($original)
        ->and($service->pendingProducts($order)->count())->toBe(1);
    expect(fn () => $service->fill($order->id, $product->id, $product->serial, '2501', cycleOperator()))->toThrow(ValidationException::class);
    expect(ProductCycle::count())->toBe(3);
});

it('waits five whole calendar dates after the last repair fill and excludes recycled members', function () {
    [$order, $products] = cycleReview(['repaired', 'recycled', 'repaired']);
    $filling = app(ProductionFillingService::class);
    $filling->fill($order->id, $products[0]->id, $products[0]->serial, 2501, cycleOperator());
    $this->travelTo(Carbon::parse('2026-10-15 23:59:00'));
    $last = $filling->fill($order->id, $products[2]->id, $products[2]->serial, 2502, cycleOperator());
    expect($last['completed'])->toBeTrue()->and($order->fresh()->wc_id)->toBe(23);
    foreach ([$products[0], $products[2]] as $product) {
        $cycle = $product->cycles()->reorder()->orderByDesc('cycle_number')->first();
        expect($cycle->waiting_started_on->toDateString())->toBe('2026-10-15')
            ->and($cycle->quality_available_on->toDateString())->toBe('2026-10-20');
    }
    $this->travelTo(Carbon::parse('2026-10-19 23:59:59'));
    expect(app(ProductionWaitingService::class)->advance($order->id))->toBeFalse();
    $this->travelTo(Carbon::parse('2026-10-20 00:00:00'));
    $this->artisan('workorders:move-after-5days')->assertExitCode(0);
    expect($order->fresh()->wc_id)->toBe(24)
        ->and($products[0]->fresh()->location_id)->toBe(43)
        ->and($products[1]->fresh()->location_id)->toBe(45)
        ->and($products[1]->cycles()->count())->toBe(1)
        ->and(app(ProductionWaitingService::class)->advance($order->id))->toBeFalse();
});

it('approves against the new fill weight and retains every previous cycle and repair', function () {
    [$order, $products] = cycleReview(['repaired']);
    $product = $products[0];
    $original = $product->cycles()->first()->getRawOriginal();
    $repair = ProductRepair::first()->getRawOriginal();
    app(ProductionFillingService::class)->fill($order->id, $product->id, $product->serial, 2503, cycleOperator());
    $this->travelTo(Carbon::parse('2026-10-18 00:00:00'));
    app(ProductionWaitingService::class)->advance($order->id);
    $result = app(ProductionQualityService::class)->complete($order->id, $product->id, $product->serial, 2507, cycleOperator());
    expect($result['completed'])->toBeTrue()->and($result['measurement']['approved'])->toBeTrue()
        ->and($result['measurement']['fill_weight'])->toBe('2503.0000')
        ->and($product->fresh()->location_id)->toBe(44)->and($product->fresh()->status_id)->toBe(56)
        ->and($product->fresh()->work_order_id)->toBeNull()
        ->and($product->fresh()->activeAssignment->work_order_product_id)->toBeNull()
        ->and($product->cycles()->first()->getRawOriginal())->toBe($original)
        ->and(ProductRepair::first()->getRawOriginal())->toBe($repair)
        ->and($order->fresh()->status_id)->toBe(16)
        ->and(Products::count())->toBe(1);
});

it('creates another Review on a repeated rejection and allows a third production cycle', function () {
    [$order, $products] = cycleReview(['repaired']);
    $product = $products[0];
    $serial = $product->serial;
    $origin = $product->production_work_order_id;
    $filling = app(ProductionFillingService::class);
    $quality = app(ProductionQualityService::class);
    $filling->fill($order->id, $product->id, $serial, 2502, cycleOperator());
    $this->travelTo(Carbon::parse('2026-10-18'));
    app(ProductionWaitingService::class)->advance($order->id);
    $measurement = $quality->measure($order->id, $product->id, $serial, 2490, cycleOperator());
    expect(fn () => $quality->complete($order->id, $product->id, $serial, 2490, cycleOperator()))->toThrow(ValidationException::class);
    $result = $quality->complete($order->id, $product->id, $serial, 2490, cycleOperator(), $measurement);
    $next = $result['review_order'];
    expect($next->id)->not->toBe($order->id)->and($product->fresh()->work_order_id)->toBe($next->id)
        ->and($quality->pendingProducts($order)->count())->toBe(0);
    $cycles = $product->cycles()->get()->map->getRawOriginal()->all();
    $reviews = app(ReviewWorkOrderService::class);
    $reviews->close($next->id, cycleOperator());
    $reviews->approve($next->id, cycleOperator());
    $reviews->start($next->id, cycleOperator());
    cycleInspection($next->fresh(), $product->fresh());
    $filling->fill($next->id, $product->id, $serial, 2501, cycleOperator());
    $this->travelTo(Carbon::parse('2026-10-23'));
    app(ProductionWaitingService::class)->advance($next->id);
    $quality->complete($next->id, $product->id, $serial, 2500, cycleOperator());
    expect($product->cycles()->count())->toBe(3)->and(ProductRepair::count())->toBe(2)
        ->and($product->cycles()->limit(2)->get()->map->getRawOriginal()->all())->toBe($cycles)
        ->and($product->fresh()->serial)->toBe($serial)
        ->and($product->fresh()->production_work_order_id)->toBe($origin)
        ->and($product->fresh()->qualified)->toBeTrue()
        ->and(WorkOrderProduct::where('product_id', $product->id)->where('purpose', 'review')->count())->toBe(2);
});

it('does not complete Review quality while another repaired member is still pending', function () {
    [$order, $products] = cycleReview();
    foreach ($products as $product) {
        app(ProductionFillingService::class)->fill($order->id, $product->id, $product->serial, 2500, cycleOperator());
    }
    $this->travelTo(Carbon::parse('2026-10-18'));
    app(ProductionWaitingService::class)->advance($order->id);
    $quality = app(ProductionQualityService::class);
    $a = $quality->complete($order->id, $products[0]->id, $products[0]->serial, 2500, cycleOperator());
    expect($a['completed'])->toBeFalse()->and($order->fresh()->wc_id)->toBe(24)
        ->and($quality->pendingProducts($order)->count())->toBe(1);
    $quality->complete($order->id, $products[1]->id, $products[1]->serial, 2500, cycleOperator());
    expect($order->fresh()->status_id)->toBe(16);
});

it('rolls back new cycle creation pointers and current weights on an audit failure', function () {
    [$order, $products] = cycleReview(['repaired']);
    $before = $products[0]->fresh()->getRawOriginal();
    $pointer = $products[0]->fresh()->activeAssignment->getRawOriginal();
    DB::statement('CREATE TRIGGER fail_cycle_audit BEFORE INSERT ON history BEGIN SELECT RAISE(ABORT, "audit failed"); END');
    expect(fn () => app(ProductionFillingService::class)->fill($order->id, $products[0]->id, $products[0]->serial, 2500, cycleOperator()))->toThrow(QueryException::class);
    expect(ProductCycle::count())->toBe(1)
        ->and($products[0]->fresh()->getRawOriginal())->toBe($before)
        ->and($products[0]->fresh()->activeAssignment->getRawOriginal())->toBe($pointer)
        ->and($order->fresh()->wc_id)->toBe(22);
});

it('uses current repair cycles for the filling and quality editor counters', function () {
    [$order, $products] = cycleReview(['repaired', 'recycled']);
    $this->actingAs(cycleOperator());
    Livewire::test(WorkOrderFillingEditor::class, ['workOrder' => $order])
        ->assertViewHas('total', 1)->assertViewHas('pendingCount', 1)
        ->set('serialInput', $products[0]->serial)->call('scanSerial')
        ->set('currentProductData.weight', 2500)->call('saveAndNext')
        ->assertHasNoErrors()->assertViewHas('filledCount', 1)->assertSet('selectedProductId', null);
    $this->travelTo(Carbon::parse('2026-10-18'));
    app(ProductionWaitingService::class)->advance($order->id);
    Livewire::test(WorkOrderQualityEditor::class, ['workOrder' => $order->fresh()])
        ->assertViewHas('total', 1)->assertViewHas('pendingCount', 1)
        ->set('serialInput', $products[0]->serial)->call('scanSerial')
        ->set('currentProductData.weight', 2500)->call('saveAndNext')
        ->assertHasNoErrors()->assertViewHas('completedCount', 1);
});

it('rejects recycled tubes and altered operational pointers without creating cycles', function () {
    [$order, $products] = cycleReview(['repaired', 'recycled']);
    $filling = app(ProductionFillingService::class);
    expect(fn () => $filling->select($order->id, $products[1]->serial, cycleOperator()))->toThrow(ValidationException::class);
    DB::table('product_active_assignments')->where('product_id', $products[0]->id)->update(['product_cycle_id' => null]);
    expect(fn () => $filling->fill($order->id, $products[0]->id, $products[0]->serial, 2500, cycleOperator()))->toThrow(ValidationException::class);
    expect(ProductCycle::count())->toBe(2);
});

it('does not trust previous waiting dates for a new repair cycle', function () {
    [$order, $products] = cycleReview(['repaired']);
    $product = $products[0];
    app(ProductionFillingService::class)->fill($order->id, $product->id, $product->serial, 2500, cycleOperator());
    $order->update(['wc_id' => 24]);
    $product->update(['location_id' => 43]);
    expect(fn () => app(ProductionQualityService::class)->select($order->id, $product->serial, cycleOperator()))->toThrow(ValidationException::class);
    expect(ProductCycle::where('cycle_number', 2)->value('quality_completed'))->toBeFalse();
});

it('blocks repair quality when cycle waiting dates are missing or shortened', function () {
    [$order, $products] = cycleReview(['repaired']);
    $product = $products[0];
    app(ProductionFillingService::class)->fill($order->id, $product->id, $product->serial, 2500, cycleOperator());
    $this->travelTo(Carbon::parse('2026-10-18'));
    app(ProductionWaitingService::class)->advance($order->id);
    $cycle = $product->cycles()->reorder()->orderByDesc('cycle_number')->first();
    $cycle->update(['quality_available_on' => null]);
    expect(fn () => app(ProductionQualityService::class)->select($order->id, $product->serial, cycleOperator()))->toThrow(ValidationException::class);
    $cycle->update(['quality_available_on' => '2026-10-14']);
    expect(fn () => app(ProductionQualityService::class)->select($order->id, $product->serial, cycleOperator()))->toThrow(ValidationException::class);
});

it('requires all inspections to be complete even if the Review center is changed manually', function () {
    [$order, $products] = cycleReview();
    DB::table('product_repairs')->where('product_id', $products[1]->id)->update(['result' => 'pending', 'reviewed_at' => null]);
    expect(fn () => app(ProductionFillingService::class)->select($order->id, $products[0]->serial, cycleOperator()))->toThrow(ValidationException::class);
    expect(ProductCycle::count())->toBe(2);
});

it('revalidates identity permissions and model tolerance before creating a repair cycle', function () {
    [$order, $products] = cycleReview();
    $service = app(ProductionFillingService::class);
    expect(fn () => $service->fill($order->id, $products[0]->id, $products[1]->serial, 2500, cycleOperator()))->toThrow(ValidationException::class);
    expect(fn () => $service->fill($order->id, $products[0]->id, $products[0]->serial, 2600, cycleOperator()))->toThrow(ValidationException::class);
    expect(fn () => $service->fill($order->id, $products[0]->id, $products[0]->serial, 2500, cycleOperator(false)))->toThrow(HttpException::class);
    expect(ProductCycle::count())->toBe(2);
});

it('rolls back scheduler movements and automatic audit together on failure', function () {
    [$order, $products] = cycleReview(['repaired']);
    app(ProductionFillingService::class)->fill($order->id, $products[0]->id, $products[0]->serial, 2500, cycleOperator());
    $this->travelTo(Carbon::parse('2026-10-18'));
    DB::statement('CREATE TRIGGER fail_wait_audit BEFORE INSERT ON history BEGIN SELECT RAISE(ABORT, "audit failed"); END');
    expect(fn () => app(ProductionWaitingService::class)->advance($order->id))->toThrow(QueryException::class);
    expect($order->fresh()->wc_id)->toBe(23)->and($products[0]->fresh()->location_id)->toBe(42);
});

it('retains legacy production waiting without assuming catalog identifiers', function () {
    $order = traceOrder();
    $order->update(['status_id' => 13, 'wc_id' => 23, 'wc_changed_at' => '2026-10-08']);
    $product = traceProduct($order, ['assambled' => true, 'qualified' => false, 'location_id' => 42, 'fill_date' => '2026-10-08 23:59:00']);
    expect(app(ProductionWaitingService::class)->advance($order->id))->toBeTrue()
        ->and($order->fresh()->wc_id)->toBe(24)->and($product->fresh()->location_id)->toBe(43);
    $history = $product->history()->first();
    expect($history->user_id)->toBeNull()->and($history->metadata['automatic'])->toBeTrue()
        ->and($history->product_cycle_id)->toBeNull();
    expect(app(ProductionQualityService::class)->select($order->id, $product->serial, cycleOperator())->id)->toBe($product->id);
});

it('keeps a waiting order unchanged if one member has invalid dates or location', function () {
    [$order, $products] = cycleReview();
    foreach ($products as $product) {
        app(ProductionFillingService::class)->fill($order->id, $product->id, $product->serial, 2500, cycleOperator());
    }
    $this->travelTo(Carbon::parse('2026-10-18'));
    $products[1]->update(['location_id' => 45]);
    $this->artisan('workorders:move-after-5days')->assertExitCode(0);
    expect($order->fresh()->wc_id)->toBe(23)->and($products[0]->fresh()->location_id)->toBe(42);
});

it('preserves existing history while allowing automatic events and refuses unsafe rollback', function () {
    $migration = require database_path('migrations/2026_10_13_120000_allow_automatic_history_events.php');
    $migration->down();
    [$order, $products] = cycleReview(['repaired']);
    $original = History::get()->map->getRawOriginal()->all();
    $migration->up();
    expect(History::get()->map->getRawOriginal()->all())->toBe($original);
    app(ProductionFillingService::class)->fill($order->id, $products[0]->id, $products[0]->serial, 2500, cycleOperator());
    $this->travelTo(Carbon::parse('2026-10-18'));
    app(ProductionWaitingService::class)->advance($order->id);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
});

it('counts the originating Review cycle even if a rejected member already starts its next cycle', function () {
    [$order, $products] = cycleReview();
    foreach ($products as $product) {
        app(ProductionFillingService::class)->fill($order->id, $product->id, $product->serial, 2500, cycleOperator());
    }
    $this->travelTo(Carbon::parse('2026-10-18'));
    app(ProductionWaitingService::class)->advance($order->id);
    $quality = app(ProductionQualityService::class);
    $measurement = $quality->measure($order->id, $products[0]->id, $products[0]->serial, 2490, cycleOperator());
    $result = $quality->complete($order->id, $products[0]->id, $products[0]->serial, 2490, cycleOperator(), $measurement);
    expect($result['completed'])->toBeFalse();
    $next = $result['review_order'];
    $reviews = app(ReviewWorkOrderService::class);
    $reviews->close($next->id, cycleOperator());
    $reviews->approve($next->id, cycleOperator());
    $reviews->start($next->id, cycleOperator());
    cycleInspection($next->fresh(), $products[0]->fresh());
    app(ProductionFillingService::class)->fill($next->id, $products[0]->id, $products[0]->serial, 2500, cycleOperator());
    expect($quality->pendingProducts($order)->count())->toBe(1);
    $last = $quality->complete($order->id, $products[1]->id, $products[1]->serial, 2500, cycleOperator());
    expect($last['completed'])->toBeTrue()->and($order->fresh()->status_id)->toBe(16)
        ->and($next->fresh()->wc_id)->toBe(23);
});

it('rolls a second rejection back when its Review assignment audit fails', function () {
    [$order, $products] = cycleReview(['repaired']);
    $product = $products[0];
    app(ProductionFillingService::class)->fill($order->id, $product->id, $product->serial, 2500, cycleOperator());
    $this->travelTo(Carbon::parse('2026-10-18'));
    app(ProductionWaitingService::class)->advance($order->id);
    $quality = app(ProductionQualityService::class);
    $measurement = $quality->measure($order->id, $product->id, $product->serial, 2490, cycleOperator());
    $cycle = $product->cycles()->reorder()->orderByDesc('cycle_number')->first();
    $before = $cycle->getRawOriginal();
    $count = WorkOrder::count();
    DB::statement('CREATE TRIGGER fail_new_review BEFORE INSERT ON history WHEN NEW.process = "Review assigned" BEGIN SELECT RAISE(ABORT, "audit failed"); END');
    expect(fn () => $quality->complete($order->id, $product->id, $product->serial, 2490, cycleOperator(), $measurement))->toThrow(QueryException::class);
    expect($cycle->fresh()->getRawOriginal())->toBe($before)->and(WorkOrder::count())->toBe($count)
        ->and($product->fresh()->work_order_id)->toBe($order->id)
        ->and($product->fresh()->activeAssignment->product_cycle_id)->toBe($cycle->id);
});
