<?php

use App\Filament\Pages\ReviewOrders;
use App\Models\ProductActiveAssignment;
use App\Models\ProductCycle;
use App\Models\Products;
use App\Models\Serial;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderProduct;
use App\Policies\WorkOrderPolicy;
use App\Services\ProductTraceabilityService;
use App\Services\ReviewWorkOrderService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../Support/ProductionDatabase.php';

function reviewActor(bool $administrative = true, bool $operator = true): User
{
    $user = Mockery::mock(User::class)->makePartial();
    $user->forceFill(['id' => 1, 'name' => 'Reviewer']);
    $user->shouldReceive('hasRole')->with(['Admin', 'Manager'])->andReturn($administrative);
    $user->shouldReceive('hasRole')->with(['Admin', 'Manager', 'Worker'])->andReturn($operator);

    return $user;
}

function rejectedReviewCycle(?WorkOrder $origin = null, int $asset = 31): ProductCycle
{
    $origin ??= traceOrder();
    $product = traceProduct($origin, ['asset_id' => $asset, 'qualified' => false, 'location_id' => 43]);
    $association = (new ProductTraceabilityService)->recordProductionOrigin($product, $origin, 17);
    $cycle = ProductCycle::create([
        'product_id' => $product->id, 'work_order_product_id' => $association->id,
        'cycle_number' => 1, 'source' => 'production', 'asset_id' => $asset,
        'fill_weight' => '2500.0000', 'fill_completed' => true,
        'quality_weight' => '2490.0000', 'quality_completed' => true, 'quality_result' => 'rejected',
        'quality_by' => 1, 'quality_date' => now(), 'completed_at' => now(),
    ]);
    (new ProductTraceabilityService)->claim($association, $cycle, 1);

    return $cycle->fresh();
}

beforeEach(function () {
    setupTraceabilityDatabase();
    phaseOneUp();
    config(['production.catalog_ids' => []]);
    DB::table('wo_status')->insert([['id' => 12, 'name' => 'Approved'], ['id' => 13, 'name' => 'inProcess']]);
    DB::table('locations')->insert(['id' => 43, 'name' => 'Quality']);
    phaseFourUp();
    (require database_path('migrations/2026_10_13_110000_add_review_location.php'))->up();
});

it('adopts existing Review catalogs and applies their migration idempotently without changing IDs', function () {
    $migration = require database_path('migrations/2026_10_13_100000_add_review_work_order_catalogs.php');
    $migration->up();
    $migration->up();
    expect(DB::table('wo_type')->where('name', 'Review')->count())->toBe(1)
        ->and(DB::table('wo_type')->where('name', 'Review')->value('id'))->toBe(22)
        ->and(DB::table('work_centers')->where('name', 'Review')->count())->toBe(1);
    DB::table('wo_type')->insert(['id' => 25, 'name' => ' review ']);
    expect(fn () => $migration->up())->toThrow(RuntimeException::class);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
});

it('creates a mixed model Review order using the existing identities and preserves rejection history', function () {
    $service = app(ReviewWorkOrderService::class);
    $a = rejectedReviewCycle();
    $b = rejectedReviewCycle(null, 32);
    $original = $a->getRawOriginal();
    $productData = $a->product->getRawOriginal();
    $first = $service->enqueue($a, reviewActor());
    $second = $service->enqueue($b, reviewActor());
    $order = $first->workOrder;
    expect($second->work_order_id)->toBe($order->id)->and($order->type_id)->toBe(22)
        ->and($order->asset_id)->toBeNull()->and($order->quant)->toBe(2)
        ->and($order->review_receiving_open)->toBeTrue()->and($order->status_id)->toBe(11)
        ->and($order->name)->toBe('WR000001')->and($order->wc_id)->toBeNull()
        ->and(Products::count())->toBe(2)->and(Serial::count())->toBe(0)
        ->and($a->fresh()->getRawOriginal())->toBe($original)
        ->and($a->product->fresh()->serial)->toBe($productData['serial'])
        ->and($a->product->fresh()->weight)->toBe($productData['weight'])
        ->and($a->product->fresh()->f_weight)->toBe($productData['f_weight'])
        ->and($a->product->fresh()->production_work_order_id)->toBe($productData['production_work_order_id'])
        ->and($a->product->fresh()->work_order_id)->toBe($order->id)
        ->and($a->product->fresh()->activeAssignment->work_order_product_id)->toBe($first->id)
        ->and($first->rejectedCycle->id)->toBe($a->id)
        ->and($a->product->history()->first()->work_order_id)->toBe($order->id);
});

it('incorporates the same confirmed rejection only once even after the order closes', function () {
    $cycle = rejectedReviewCycle();
    $service = app(ReviewWorkOrderService::class);
    $first = $service->enqueue($cycle, reviewActor());
    $service->close($first->work_order_id, reviewActor());
    $events = $cycle->product->history()->count();
    $again = $service->enqueue($cycle, reviewActor());
    expect($again->id)->toBe($first->id)
        ->and(WorkOrder::where('type_id', 22)->count())->toBe(1)
        ->and($first->workOrder->fresh()->quant)->toBe(1)
        ->and($cycle->product->history()->count())->toBe($events);
});

it('closes exactly at fifty and uses a new order for the fifty first product', function () {
    $origin = traceOrder();
    $service = app(ReviewWorkOrderService::class);
    $firstOrderId = null;
    for ($i = 0; $i < 51; $i++) {
        $association = $service->enqueue(rejectedReviewCycle($origin, $i % 2 ? 32 : 31), reviewActor());
        $firstOrderId ??= $association->work_order_id;
    }
    $first = WorkOrder::find($firstOrderId);
    $second = $association->workOrder;
    expect($first->quant)->toBe(50)->and($first->productAssociations()->where('purpose', 'review')->count())->toBe(50)
        ->and($first->review_receiving_open)->toBeFalse()->and($first->review_closed_at)->not->toBeNull()
        ->and($first->review_closed_by)->toBe(1)->and($first->status_id)->toBe(11)
        ->and($second->id)->not->toBe($first->id)->and($second->quant)->toBe(1)
        ->and($second->review_receiving_open)->toBeTrue()->and($second->name)->toBe('WR000002')
        ->and(Products::count())->toBe(51)->and(Serial::count())->toBe(0);
});

it('uses current membership instead of stale quantity when two operators have seen forty nine', function () {
    $service = app(ReviewWorkOrderService::class);
    $origin = traceOrder();
    for ($i = 0; $i < 49; $i++) {
        $association = $service->enqueue(rejectedReviewCycle($origin), reviewActor());
    }
    $order = $association->workOrder;
    $a = rejectedReviewCycle($origin);
    $b = rejectedReviewCycle($origin);
    DB::table('work_orders')->where('id', $order->id)->update(['quant' => 1]);
    $last = $service->enqueue($a, reviewActor());
    $next = $service->enqueue($b, reviewActor());
    expect($last->work_order_id)->toBe($order->id)->and($order->fresh()->quant)->toBe(50)
        ->and($next->work_order_id)->not->toBe($order->id);
});

it('manually closes a partial order and prevents approval while still receiving', function () {
    $service = app(ReviewWorkOrderService::class);
    $association = $service->enqueue(rejectedReviewCycle(), reviewActor());
    $order = $association->workOrder;
    expect(fn () => $service->approve($order->id, reviewActor()))->toThrow(ValidationException::class);
    $closed = $service->close($order->id, reviewActor());
    $again = $service->close($order->id, reviewActor());
    expect($closed->review_receiving_open)->toBeFalse()->and($closed->quant)->toBe(1)
        ->and($again->review_closed_at->toDateTimeString())->toBe($closed->review_closed_at->toDateTimeString());
    $next = $service->enqueue(rejectedReviewCycle(), reviewActor());
    expect($next->work_order_id)->not->toBe($order->id);
});

it('approves and starts closed Review orders in the Review center without assembly or serial generation', function () {
    $service = app(ReviewWorkOrderService::class);
    $cycle = rejectedReviewCycle();
    $association = $service->enqueue($cycle, reviewActor());
    $service->close($association->work_order_id, reviewActor());
    $approved = $service->approve($association->work_order_id, reviewActor());
    $events = $cycle->product->history()->count();
    $service->approve($association->work_order_id, reviewActor());
    expect($cycle->product->history()->count())->toBe($events)
        ->and($approved->status_id)->toBe(12)->and($approved->wc->name)->toBe('Review');
    $started = $service->start($approved->id, reviewActor());
    $service->start($approved->id, reviewActor());
    expect($started->status_id)->toBe(13)->and($started->wc->name)->toBe('Review')
        ->and($started->products()->count())->toBe(1)->and(Products::count())->toBe(1)
        ->and(Serial::count())->toBe(0)->and($started->review_receiving_open)->toBeFalse();
});

it('enforces administrative authorization for closing approval start and backlog incorporation', function () {
    $cycle = rejectedReviewCycle();
    $service = app(ReviewWorkOrderService::class);
    $association = $service->enqueue($cycle, reviewActor(false, true));
    foreach ([null, reviewActor(false, false), reviewActor(false, true)] as $actor) {
        expect(fn () => $service->close($association->work_order_id, $actor))->toThrow(HttpException::class);
        expect(fn () => $service->approve($association->work_order_id, $actor))->toThrow(HttpException::class);
        expect(fn () => $service->start($association->work_order_id, $actor))->toThrow(HttpException::class);
        expect(fn () => $service->incorporatePending($cycle->id, $actor))->toThrow(HttpException::class);
    }
    expect(fn () => $service->enqueue($cycle, reviewActor(false, false)))->toThrow(HttpException::class);
    $this->actingAs(reviewActor(false, true));
    expect(ReviewOrders::canAccess())->toBeFalse();
    expect(fn () => (new ReviewOrders)->mount())->toThrow(HttpException::class);
});

it('incorporates confirmed rejections left by phase three without changing the immutable cycle', function () {
    $cycle = rejectedReviewCycle();
    $original = $cycle->getRawOriginal();
    $service = app(ReviewWorkOrderService::class);
    expect($service->pendingRejections()->count())->toBe(1);
    $association = $service->incorporatePending($cycle->id, reviewActor());
    expect($association->rejected_cycle_id)->toBe($cycle->id)
        ->and($service->pendingRejections()->count())->toBe(0)
        ->and($cycle->fresh()->getRawOriginal())->toBe($original);
});

it('rejects cycles that are approved incomplete or superseded by a later cycle', function () {
    $service = app(ReviewWorkOrderService::class);
    $cycle = rejectedReviewCycle();
    DB::table('product_cycles')->where('id', $cycle->id)->update(['quality_result' => 'approved']);
    expect(fn () => $service->enqueue($cycle, reviewActor()))->toThrow(ValidationException::class);
    DB::table('product_cycles')->where('id', $cycle->id)->update(['quality_result' => 'rejected', 'completed_at' => null]);
    expect(fn () => $service->enqueue($cycle, reviewActor()))->toThrow(ValidationException::class);
    DB::table('product_cycles')->where('id', $cycle->id)->update(['completed_at' => now()]);
    ProductCycle::create(['product_id' => $cycle->product_id, 'cycle_number' => 2, 'source' => 'repair']);
    expect(fn () => $service->enqueue($cycle, reviewActor()))->toThrow(ValidationException::class);
    expect($service->pendingRejections()->count())->toBe(0);
});

it('prevents incorporation when another operational association holds the product', function () {
    $cycle = rejectedReviewCycle();
    (new ProductTraceabilityService)->release($cycle->association);
    $other = traceAssociation($cycle->product, traceOrder(23), 'distribution');
    (new ProductTraceabilityService)->claim($other, $cycle);
    expect(fn () => app(ReviewWorkOrderService::class)->enqueue($cycle, reviewActor()))->toThrow(ValidationException::class);
    expect(WorkOrder::where('type_id', 22)->count())->toBe(0);
});

it('rolls back creation membership claims and product move if review auditing fails', function () {
    $cycle = rejectedReviewCycle();
    $originalOrder = $cycle->product->work_order_id;
    DB::unprepared("CREATE TRIGGER reject_review_history BEFORE INSERT ON history WHEN NEW.process = 'Review assigned' BEGIN SELECT RAISE(ABORT, 'test review audit failure'); END");
    expect(fn () => app(ReviewWorkOrderService::class)->enqueue($cycle, reviewActor()))->toThrow(QueryException::class);
    expect(WorkOrder::where('type_id', 22)->count())->toBe(0)
        ->and(WorkOrderProduct::where('purpose', 'review')->count())->toBe(0)
        ->and($cycle->product->fresh()->work_order_id)->toBe($originalOrder)
        ->and($cycle->product->fresh()->activeAssignment->work_order_product_id)->toBe($cycle->work_order_product_id)
        ->and($cycle->association->fresh()->state)->toBe('recorded');
});

it('enforces uniqueness and same product rejection context in the database', function () {
    $a = rejectedReviewCycle();
    $b = rejectedReviewCycle();
    $service = app(ReviewWorkOrderService::class);
    $association = $service->enqueue($a, reviewActor());
    $other = traceOrder(22);
    expect(fn () => DB::table('work_order_products')->insert([
        'product_id' => $a->product_id, 'work_order_id' => $other->id, 'purpose' => 'review', 'rejected_cycle_id' => $a->id,
    ]))->toThrow(QueryException::class);
    expect(fn () => DB::table('work_order_products')->insert([
        'product_id' => $a->product_id, 'work_order_id' => $other->id, 'purpose' => 'review', 'rejected_cycle_id' => $b->id,
    ]))->toThrow(QueryException::class);
    expect(fn () => $association->update(['rejected_cycle_id' => $b->id]))->toThrow(LogicException::class);
    $migration = require database_path('migrations/2026_10_13_100100_add_rejected_cycle_to_review_associations.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
});

it('refuses approval if active members changed and blocks generic editing', function () {
    $service = app(ReviewWorkOrderService::class);
    $cycle = rejectedReviewCycle();
    $association = $service->enqueue($cycle, reviewActor());
    $service->close($association->work_order_id, reviewActor());
    expect((new WorkOrderPolicy)->update(reviewActor(), $association->workOrder))->toBeFalse();
    ProductActiveAssignment::whereKey($cycle->product_id)->update(['work_order_product_id' => null, 'product_cycle_id' => null]);
    expect(fn () => $service->approve($association->work_order_id, reviewActor()))->toThrow(ValidationException::class);
    expect($association->workOrder->fresh()->status_id)->toBe(11);
});

it('preserves Review behavior even when its type happens to be the legacy production ID', function () {
    DB::table('wo_type')->where('id', 22)->update(['name' => 'Other']);
    DB::table('wo_type')->insert(['id' => 1, 'name' => 'Review']);
    DB::table('wo_status')->where('id', 12)->update(['name' => 'Other']);
    DB::table('wo_status')->insert(['id' => 2, 'name' => 'Approved']);
    $service = app(ReviewWorkOrderService::class);
    $association = $service->enqueue(rejectedReviewCycle(), reviewActor());
    $service->close($association->work_order_id, reviewActor());
    $order = $service->approve($association->work_order_id, reviewActor());
    expect($order->type_id)->toBe(1)->and($order->status_id)->toBe(2)
        ->and($order->wc->name)->toBe('Review')->and(Products::count())->toBe(1)
        ->and(Serial::count())->toBe(0);
});

it('rolls back the rejection link migration only while it contains no review data', function () {
    $migration = require database_path('migrations/2026_10_13_100100_add_rejected_cycle_to_review_associations.php');
    $migration->down();
    expect(Schema::hasColumn('work_order_products', 'rejected_cycle_id'))->toBeFalse();
    $migration->up();
    expect(Schema::hasColumn('work_order_products', 'rejected_cycle_id'))->toBeTrue();
});

it('renders mixed model orders and performs confirmed administrative actions through Filament', function () {
    config(['permission.cache.store' => 'array']);
    app('cache')->setDefaultDriver('array');
    app(PermissionRegistrar::class)->initializeCache();
    (require database_path('migrations/2025_10_02_192820_create_permission_tables.php'))->up();
    Role::findOrCreate('Admin', 'web');
    $user = User::findOrFail(1);
    $user->assignRole('Admin');
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $service = app(ReviewWorkOrderService::class);
    $a = rejectedReviewCycle();
    $b = rejectedReviewCycle(null, 32);
    $first = $service->enqueue($a, $user);
    $service->enqueue($b, $user);
    Livewire::test(ReviewOrders::class)
        ->assertSee('2/50 productos')->assertSee('Model A')->assertSee('Model B')
        ->assertSee($a->product->serial)->assertSee($b->product->serial)
        ->callAction('closeReview', arguments: ['orderId' => $first->work_order_id])->assertHasNoErrors()
        ->assertSee('Pendiente de aprobación.')
        ->callAction('approveReview', arguments: ['orderId' => $first->work_order_id])->assertHasNoErrors()
        ->callAction('startReview', arguments: ['orderId' => $first->work_order_id])->assertHasNoErrors();
    expect($first->workOrder->fresh()->status_id)->toBe(13);
});
