<?php

use App\Filament\Pages\Review;
use App\Livewire\WorkOrderReviewEditor;
use App\Models\ProductCycle;
use App\Models\ProductRepair;
use App\Models\User;
use App\Services\ProductTraceabilityService;
use App\Services\ReviewInspectionService;
use App\Services\ReviewWorkOrderService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../Support/ProductionDatabase.php';

function inspectionActor(bool $allowed = true): User
{
    $user = Mockery::mock(User::class)->makePartial();
    $user->forceFill(['id' => 1, 'name' => 'Reviewer']);
    $user->shouldReceive('hasRole')->andReturn($allowed);

    return $user;
}

function inspectionOrder(int $count = 2): array
{
    $service = app(ReviewWorkOrderService::class);
    $products = [];
    for ($i = 0; $i < $count; $i++) {
        $origin = traceOrder();
        $product = traceProduct($origin, ['qualified' => false, 'location_id' => 43, 'status_id' => 53]);
        $association = (new ProductTraceabilityService)->recordProductionOrigin($product, $origin, 17);
        $cycle = ProductCycle::create([
            'product_id' => $product->id, 'work_order_product_id' => $association->id,
            'cycle_number' => 1, 'source' => 'production', 'asset_id' => 31,
            'fill_weight' => '2500.0000', 'fill_completed' => true,
            'quality_weight' => '2490.0000', 'quality_completed' => true, 'quality_result' => 'rejected',
            'quality_by' => 1, 'quality_date' => now(), 'completed_at' => now(),
        ]);
        (new ProductTraceabilityService)->claim($association, $cycle, 1);
        $member = $service->enqueue($cycle, inspectionActor());
        $products[] = $product->fresh();
    }
    $service->close($member->work_order_id, inspectionActor());
    $service->approve($member->work_order_id, inspectionActor());

    return [$service->start($member->work_order_id, inspectionActor()), $products];
}

function inspectionDecision(string $result = 'repaired'): array
{
    return ['result' => $result, 'diagnosis' => 'Fuga en válvula',
        'repair_description' => 'Válvula sustituida', 'recycling_reason' => 'Tubo fisurado'];
}

beforeEach(function () {
    setupTraceabilityDatabase();
    phaseOneUp();
    config(['production.catalog_ids' => []]);
    DB::table('wo_status')->insert([['id' => 12, 'name' => 'Approved'], ['id' => 13, 'name' => 'inProcess'], ['id' => 16, 'name' => 'Ready']]);
    DB::table('locations')->insert([['id' => 43, 'name' => 'Quality'], ['id' => 45, 'name' => 'Recycle']]);
    DB::table('status')->insert([['id' => 53, 'name' => 'inProcess'], ['id' => 55, 'name' => 'Recycled']]);
    DB::table('work_centers')->insert(['id' => 22, 'name' => 'Fill']);
    phaseFourUp();
    (require database_path('migrations/2026_10_13_110000_add_review_location.php'))->up();
});

it('scans only the exact pending member without writing data', function () {
    [$order, $products] = inspectionOrder();
    $before = $products[0]->fresh()->getRawOriginal();
    $selected = app(ReviewInspectionService::class)->select($order->id, strtolower($products[0]->serial), inspectionActor());
    expect($selected->product_id)->toBe($products[0]->id)
        ->and($products[0]->fresh()->getRawOriginal())->toBe($before)
        ->and(ProductRepair::count())->toBe(0);
    expect(fn () => app(ReviewInspectionService::class)->select($order->id, 'UNKNOWN', inspectionActor()))->toThrow(ValidationException::class);
    $other = traceProduct();
    expect(fn () => app(ReviewInspectionService::class)->select($order->id, $other->serial, inspectionActor()))->toThrow(ValidationException::class);
});

it('retains rejection weights and advances mixed results only after every member is reviewed', function () {
    [$order, $products] = inspectionOrder();
    $service = app(ReviewInspectionService::class);
    $cycle = $products[0]->cycles()->first();
    $original = $cycle->getRawOriginal();
    $first = $service->inspect($order->id, $products[0]->id, $products[0]->serial, inspectionDecision(), inspectionActor());
    expect($first['completed'])->toBeFalse()->and($order->fresh()->wc_id)->not->toBe(22)
        ->and($cycle->fresh()->getRawOriginal())->toBe($original)
        ->and($products[0]->fresh()->filled)->toBeTrue()
        ->and($products[0]->fresh()->activeAssignment->product_cycle_id)->toBe($cycle->id);
    $second = $service->inspect($order->id, $products[1]->id, $products[1]->serial, inspectionDecision('recycled'), inspectionActor(), true);
    expect($second['completed'])->toBeTrue()->and($order->fresh()->wc_id)->toBe(22)
        ->and($order->fresh()->status_id)->toBe(13)
        ->and($products[1]->fresh()->status_id)->toBe(55)
        ->and($products[1]->fresh()->location_id)->toBe(45)
        ->and($products[1]->fresh()->work_order_id)->toBeNull()
        ->and($products[1]->fresh()->activeAssignment->work_order_product_id)->toBeNull()
        ->and(ProductCycle::count())->toBe(2)->and(ProductRepair::count())->toBe(2)
        ->and($products[0]->history()->where('product_repair_id', $first['repair']->id)->count())->toBe(1);
    expect(fn () => $first['repair']->update(['diagnosis' => 'Cambio']))->toThrow(LogicException::class);
});

it('finishes an entirely recycled order without sending it to filling', function () {
    [$order, $products] = inspectionOrder(1);
    app(ReviewInspectionService::class)->inspect($order->id, $products[0]->id, $products[0]->serial, inspectionDecision('recycled'), inspectionActor(), true);
    expect($order->fresh()->status_id)->toBe(16)->and($order->fresh()->wc_id)->toBeNull()->and(ProductCycle::count())->toBe(1);
});

it('requires diagnosis and the corresponding explanation', function ($field, $result) {
    $data = inspectionDecision($result);
    $data[$field] = '  ';
    expect(fn () => app(ReviewInspectionService::class)->validateDecision($data))->toThrow(ValidationException::class);
})->with([['diagnosis', 'repaired'], ['repair_description', 'repaired'], ['recycling_reason', 'recycled']]);

it('rejects unconfirmed recycling mismatched serials and unauthorized operators without writes', function () {
    [$order, $products] = inspectionOrder();
    $service = app(ReviewInspectionService::class);
    expect(fn () => $service->inspect($order->id, $products[0]->id, $products[0]->serial, inspectionDecision('recycled'), inspectionActor()))->toThrow(ValidationException::class);
    expect(fn () => $service->inspect($order->id, $products[0]->id, $products[1]->serial, inspectionDecision(), inspectionActor()))->toThrow(ValidationException::class);
    expect(fn () => $service->inspect($order->id, $products[0]->id, $products[0]->serial, inspectionDecision(), inspectionActor(false)))->toThrow(HttpException::class);
    expect(ProductRepair::count())->toBe(0);
});

it('prevents a second decision for a finalized member', function () {
    [$order, $products] = inspectionOrder();
    $service = app(ReviewInspectionService::class);
    $service->inspect($order->id, $products[0]->id, $products[0]->serial, inspectionDecision(), inspectionActor());
    expect(fn () => $service->inspect($order->id, $products[0]->id, $products[0]->serial, inspectionDecision('recycled'), inspectionActor(), true))->toThrow(ValidationException::class);
    expect(ProductRepair::count())->toBe(1)->and($products[0]->fresh()->status_id)->toBe(53);
});

it('rolls the whole inspection back if the audit cannot be saved', function () {
    [$order, $products] = inspectionOrder(1);
    $before = $products[0]->fresh()->getRawOriginal();
    DB::statement('CREATE TRIGGER fail_review_history BEFORE INSERT ON history BEGIN SELECT RAISE(ABORT, "audit failed"); END');
    expect(fn () => app(ReviewInspectionService::class)->inspect($order->id, $products[0]->id, $products[0]->serial, inspectionDecision('recycled'), inspectionActor(), true))->toThrow(QueryException::class);
    expect(ProductRepair::count())->toBe(0)->and($products[0]->fresh()->getRawOriginal())->toBe($before)
        ->and($products[0]->fresh()->activeAssignment->work_order_product_id)->not->toBeNull();
});

it('requires confirmation in the actual editor and cancellation leaves the tube unchanged', function () {
    [$order, $products] = inspectionOrder();
    $this->actingAs(inspectionActor());
    $editor = Livewire::test(WorkOrderReviewEditor::class, ['workOrder' => $order])
        ->set('serialInput', $products[0]->serial)->call('scanSerial')->assertSet('selectedProductId', $products[0]->id)
        ->set('decision', inspectionDecision('recycled'))->call('save')->assertHasNoErrors();
    expect(ProductRepair::count())->toBe(0);
    $editor->call('cancelRecycling')->assertSet('recyclingConfirmation', null);
    expect(ProductRepair::count())->toBe(0);
    $editor->call('save')->call('confirmRecycling')->assertHasNoErrors()->assertSet('selectedProductId', null)->assertSet('serialInput', '');
    expect(ProductRepair::first()->result)->toBe('recycled');
});

it('invalidates confirmation when the reason changes and clears a previous selection on a failed scan', function () {
    [$order, $products] = inspectionOrder();
    $this->actingAs(inspectionActor());
    $editor = Livewire::test(WorkOrderReviewEditor::class, ['workOrder' => $order])
        ->set('serialInput', $products[0]->serial)->call('scanSerial')
        ->set('decision', inspectionDecision('recycled'))->call('save')
        ->set('decision.recycling_reason', 'Otro motivo')->assertSet('recyclingConfirmation', null)
        ->call('confirmRecycling')->assertHasErrors('confirmation')
        ->set('serialInput', 'UNKNOWN')->call('scanSerial')->assertHasErrors('serialInput')->assertSet('selectedProductId', null);
    expect(ProductRepair::count())->toBe(0);
});

it('rejects duplicate serials missing assignments and orders outside Review', function () {
    [$order, $products] = inspectionOrder();
    $service = app(ReviewInspectionService::class);
    DB::table('products')->where('id', $products[1]->id)->update(['serial' => $products[0]->serial]);
    expect(fn () => $service->select($order->id, $products[0]->serial, inspectionActor()))->toThrow(ValidationException::class);
    DB::table('products')->where('id', $products[1]->id)->update(['serial' => $products[1]->serial]);
    DB::table('product_active_assignments')->where('product_id', $products[0]->id)->update(['work_order_product_id' => null, 'product_cycle_id' => null]);
    expect(fn () => $service->select($order->id, $products[0]->serial, inspectionActor()))->toThrow(ValidationException::class);
    $order->update(['wc_id' => 22]);
    expect(fn () => $service->select($order->id, $products[1]->serial, inspectionActor()))->toThrow(ValidationException::class);
    expect(ProductRepair::count())->toBe(0);
});

it('adopts the Review location without duplication and refuses destructive rollback', function () {
    $migration = require database_path('migrations/2026_10_13_110000_add_review_location.php');
    $id = DB::table('locations')->where('name', 'Review')->value('id');
    $migration->up();
    expect(DB::table('locations')->where('name', 'Review')->count())->toBe(1)
        ->and(DB::table('locations')->where('name', 'Review')->value('id'))->toBe($id);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    DB::table('locations')->insert(['name' => ' review ']);
    expect(fn () => $migration->up())->toThrow(RuntimeException::class);
});

it('renders the station for a Worker and routes camera scans to the chosen order only', function () {
    [$order, $products] = inspectionOrder();
    config(['permission.cache.store' => 'array']);
    app('cache')->setDefaultDriver('array');
    app(PermissionRegistrar::class)->initializeCache();
    $migration = collect(glob(database_path('migrations/*create_permission_tables.php')))->first();
    (require $migration)->up();
    Role::create(['name' => 'Worker', 'guard_name' => 'web']);
    $user = User::findOrFail(1);
    $user->assignRole('Worker');
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('worker'));
    Livewire::test(Review::class)->assertSee($order->name)
        ->call('openScanner', $order->id)->assertSet('scanOrderId', $order->id)
        ->call('setCode', $products[0]->serial)
        ->assertDispatched('review-camera-scanned', orderId: $order->id, code: $products[0]->serial)
        ->assertSet('scanOrderId', null);
    Livewire::test(WorkOrderReviewEditor::class, ['workOrder' => $order])
        ->call('scanCamera', $order->id + 1, $products[0]->serial)->assertSet('selectedProductId', null)
        ->call('scanCamera', $order->id, $products[0]->serial)->assertSet('selectedProductId', $products[0]->id);
    expect(ProductRepair::count())->toBe(0);
});
