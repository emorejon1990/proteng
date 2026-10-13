<?php

use App\Livewire\WorkOrderFillingEditor;
use App\Models\History;
use App\Models\ProductCycle;
use App\Models\Products;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderProduct;
use App\Services\ProductionCatalog;
use App\Services\ProductionFillingService;
use App\Services\ProductionTraceabilityBackfill;
use App\Services\ProductTraceabilityService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../Support/ProductionDatabase.php';

function fillingOrder(): WorkOrder
{
    $order = traceOrder();
    DB::table('work_orders')->where('id', $order->id)->update(['status_id' => 13, 'wc_id' => 22, 'wc_changed_at' => null]);

    return $order->fresh();
}

function fillingProduct(WorkOrder $order, array $attributes = []): Products
{
    return traceProduct($order, array_merge([
        'assambled' => true, 'filled' => false, 'qualified' => false,
        'weight' => null, 'f_weight' => null, 'fill_date' => null, 'quality_date' => null,
        'fill_by' => null, 'quality_by' => null,
    ], $attributes));
}

function fillingActor(bool $authorized = true): User
{
    $user = Mockery::mock(User::class)->makePartial();
    $user->forceFill(['id' => 1, 'name' => 'Operator']);
    $user->shouldReceive('hasRole')->with(['Admin', 'Manager', 'Worker'])->andReturn($authorized);

    return $user;
}

beforeEach(function () {
    setupTraceabilityDatabase();
    phaseOneUp();
    config(['production.catalog_ids' => []]);
    DB::table('wo_status')->insert(['id' => 13, 'name' => 'inProcess']);
    DB::table('work_centers')->insert([
        ['id' => 22, 'name' => 'Fill'], ['id' => 23, 'name' => 'Waiting'], ['id' => 24, 'name' => 'Quality'],
    ]);
    DB::table('locations')->insert(['id' => 42, 'name' => 'Waiting']);
});

it('identifies the exact scanned product in any order without writing data', function () {
    $order = fillingOrder();
    $a = fillingProduct($order);
    $b = fillingProduct($order);
    $selected = app(ProductionFillingService::class)->select($order->id, '  '.strtolower($b->serial)."\r\n", fillingActor());
    expect($selected->id)->toBe($b->id)
        ->and($a->fresh()->filled)->toBeFalse()
        ->and($b->fresh()->filled)->toBeFalse()
        ->and(WorkOrderProduct::count())->toBe(0)
        ->and(ProductCycle::count())->toBe(0)
        ->and(History::count())->toBe(0);
});

it('rejects missing unknown foreign unassembled filled and ambiguous serials', function () {
    $order = fillingOrder();
    $other = fillingOrder();
    $foreign = fillingProduct($other);
    $unassembled = fillingProduct($order, ['assambled' => false]);
    $filled = fillingProduct($order, ['filled' => true, 'weight' => 2500]);
    $qualified = fillingProduct($order, ['qualified' => true, 'f_weight' => 2500]);
    $duplicate = fillingProduct($order);
    fillingProduct($order, ['serial' => $duplicate->serial]);
    foreach (['', 'Z9999999Z', $foreign->serial, $unassembled->serial, $filled->serial, $qualified->serial, $duplicate->serial] as $serial) {
        expect(fn () => app(ProductionFillingService::class)->select($order->id, $serial, fillingActor()))->toThrow(ValidationException::class);
    }
    expect($filled->fresh()->weight)->toBe(2500.0)->and(History::count())->toBe(0);
});

it('authorizes both identification and saving on the server', function () {
    $order = fillingOrder();
    $product = fillingProduct($order);
    $service = app(ProductionFillingService::class);
    foreach ([null, fillingActor(false)] as $actor) {
        expect(fn () => $service->select($order->id, $product->serial, $actor))->toThrow(HttpException::class);
        expect(fn () => $service->fill($order->id, $product->id, $product->serial, 2500, $actor))->toThrow(HttpException::class);
    }
    expect($product->fresh()->filled)->toBeFalse();
});

it('rejects orders outside production filling or outside the running status', function () {
    $order = fillingOrder();
    $product = fillingProduct($order);
    foreach ([['wc_id' => 24], ['status_id' => 11], ['type_id' => 22]] as $change) {
        DB::table('work_orders')->where('id', $order->id)->update(array_merge(['wc_id' => 22, 'status_id' => 13, 'type_id' => 17], $change));
        expect(fn () => app(ProductionFillingService::class)->select($order->id, $product->serial, fillingActor()))->toThrow(ValidationException::class);
        expect(fn () => app(ProductionFillingService::class)->fill($order->id, $product->id, $product->serial, 2500, fillingActor()))->toThrow(ValidationException::class);
    }
});

it('saves weight audit and cycle only for the scanned product while keeping the order pending', function () {
    $order = fillingOrder();
    $a = fillingProduct($order);
    $b = fillingProduct($order);
    $result = app(ProductionFillingService::class)->fill($order->id, $b->id, $b->serial, '2500.125', fillingActor());
    $cycle = $b->cycles()->first();
    expect($result['completed'])->toBeFalse()
        ->and($a->fresh()->filled)->toBeFalse()
        ->and($a->fresh()->weight)->toBeNull()
        ->and($b->fresh()->filled)->toBeTrue()
        ->and($b->fresh()->weight)->toBe(2500.125)
        ->and($b->fresh()->fill_by)->toBe(1)
        ->and($b->fresh()->fill_date)->not->toBeNull()
        ->and($b->fresh()->location_id)->toBe(42)
        ->and($b->fresh()->production_work_order_id)->toBe($order->id)
        ->and($cycle->fill_weight)->toBe('2500.1250')
        ->and($cycle->fill_completed)->toBeTrue()
        ->and($b->history()->first()->cycle->id)->toBe($cycle->id)
        ->and($b->history()->first()->metadata['serial'])->toBe($b->serial)
        ->and($order->fresh()->wc_id)->toBe(22)
        ->and($cycle->waiting_started_on)->toBeNull();
});

it('transitions only after all products including newly added ones are filled using calendar dates', function () {
    $this->travelTo(Carbon::parse('2026-10-12 18:30:00'));
    $order = fillingOrder();
    $a = fillingProduct($order);
    $b = fillingProduct($order);
    $service = app(ProductionFillingService::class);
    $service->select($order->id, $b->serial, fillingActor());
    $c = fillingProduct($order);
    $service->fill($order->id, $b->id, $b->serial, 2500, fillingActor());
    $service->fill($order->id, $a->id, $a->serial, 2500, fillingActor());
    expect($order->fresh()->wc_id)->toBe(22);
    $result = $service->fill($order->id, $c->id, $c->serial, 2500, fillingActor());
    expect($result['completed'])->toBeTrue()
        ->and($order->fresh()->wc_id)->toBe(23)
        ->and($order->fresh()->wc_changed_at)->toBe('2026-10-12')
        ->and(Schema::getColumnType('work_orders', 'wc_changed_at'))->toBe('date')
        ->and(ProductCycle::whereDate('waiting_started_on', '2026-10-12')->count())->toBe(3)
        ->and(ProductCycle::whereDate('quality_available_on', '2026-10-17')->count())->toBe(3);
    expect(fn () => $service->fill($order->id, $c->id, $c->serial, 2499, fillingActor()))->toThrow(ValidationException::class);
    expect(History::count())->toBe(3)->and($order->fresh()->wc_changed_at)->toBe('2026-10-12');
});

it('preserves earlier filled legacy units and archives their origin before leaving filling', function () {
    $order = fillingOrder();
    $legacy = fillingProduct($order, ['filled' => true, 'weight' => 2498, 'fill_date' => '2026-10-09 12:00:00']);
    $last = fillingProduct($order);
    app(ProductionFillingService::class)->fill($order->id, $last->id, $last->serial, 2500, fillingActor());
    expect($legacy->fresh()->weight)->toBe(2498.0)
        ->and($legacy->fresh()->fill_date)->toBe('2026-10-09 12:00:00')
        ->and($legacy->fresh()->production_work_order_id)->toBe($order->id)
        ->and($legacy->cycles()->first()->fill_weight)->toBe('2498.0000')
        ->and($legacy->cycles()->first()->source)->toBe('legacy')
        ->and($legacy->history()->count())->toBe(0);
});

it('blocks duplicate saves and mismatched serial identity without overwriting weight', function () {
    $order = fillingOrder();
    $a = fillingProduct($order);
    $b = fillingProduct($order);
    $service = app(ProductionFillingService::class);
    expect(fn () => $service->fill($order->id, $a->id, $b->serial, 2500, fillingActor()))->toThrow(ValidationException::class);
    $service->fill($order->id, $a->id, $a->serial, 2500, fillingActor());
    expect(fn () => $service->fill($order->id, $a->id, $a->serial, 2501, fillingActor()))->toThrow(ValidationException::class);
    expect($a->fresh()->weight)->toBe(2500.0)->and(History::count())->toBe(1)->and(ProductCycle::count())->toBe(1);
});

it('enforces the selected product model tolerance and rejects invalid weights without partial writes', function () {
    $order = fillingOrder();
    $product = fillingProduct($order, ['asset_id' => 32]);
    foreach ([null, '', 'abc', true, [], 0, -1, 2500, 1994.99, 2005.01, '2000.12345', INF, NAN] as $weight) {
        expect(fn () => app(ProductionFillingService::class)->fill($order->id, $product->id, $product->serial, $weight, fillingActor()))->toThrow(ValidationException::class);
    }
    expect($product->fresh()->filled)->toBeFalse()->and(History::count())->toBe(0)
        ->and(ProductCycle::count())->toBe(0)->and(WorkOrderProduct::count())->toBe(0);
    app(ProductionFillingService::class)->fill($order->id, $product->id, $product->serial, '1995', fillingActor());
    expect($product->fresh()->weight)->toBe(1995.0);
});

it('revalidates station product and model values changed after identification', function () {
    $order = fillingOrder();
    $product = fillingProduct($order);
    $service = app(ProductionFillingService::class);
    $service->select($order->id, $product->serial, fillingActor());
    DB::table('asset')->where('id', 31)->update(['weight' => 2600]);
    expect(fn () => $service->fill($order->id, $product->id, $product->serial, 2500, fillingActor()))->toThrow(ValidationException::class);
    DB::table('asset')->where('id', 31)->update(['weight' => 2500]);
    DB::table('products')->where('id', $product->id)->update(['assambled' => false]);
    expect(fn () => $service->fill($order->id, $product->id, $product->serial, 2500, fillingActor()))->toThrow(ValidationException::class);
    expect(History::count())->toBe(0);
});

it('retains imported snapshots and refuses a completed historical cycle even if product flags changed', function () {
    $order = fillingOrder();
    $product = fillingProduct($order, ['weight' => 2497]);
    (new ProductionTraceabilityBackfill)->run(17, true);
    app(ProductionFillingService::class)->fill($order->id, $product->id, $product->serial, 2500, fillingActor());
    $cycle = $product->cycles()->first();
    expect((float) $cycle->legacy_snapshot['weight'])->toBe(2497.0)->and($cycle->fill_weight)->toBe('2500.0000');
    DB::table('products')->where('id', $product->id)->update(['filled' => false]);
    DB::table('work_orders')->where('id', $order->id)->update(['wc_id' => 22]);
    expect(fn () => app(ProductionFillingService::class)->select($order->id, $product->serial, fillingActor()))->toThrow(ValidationException::class);
});

it('rejects a product claimed by another order', function () {
    $order = fillingOrder();
    $product = fillingProduct($order);
    $review = traceOrder(22);
    $association = traceAssociation($product, $review, 'review');
    (new ProductTraceabilityService)->claim($association);
    expect(fn () => app(ProductionFillingService::class)->select($order->id, $product->serial, fillingActor()))->toThrow(ValidationException::class);
    expect(fn () => app(ProductionFillingService::class)->fill($order->id, $product->id, $product->serial, 2500, fillingActor()))->toThrow(ValidationException::class);
});

it('rolls back product cycle history and transition together on an audit failure', function () {
    $order = fillingOrder();
    $product = fillingProduct($order);
    DB::unprepared("CREATE TRIGGER reject_fill_history BEFORE INSERT ON history BEGIN SELECT RAISE(ABORT, 'test audit failure'); END");
    expect(fn () => app(ProductionFillingService::class)->fill($order->id, $product->id, $product->serial, 2500, fillingActor()))->toThrow(QueryException::class);
    expect($product->fresh()->filled)->toBeFalse()->and($product->fresh()->weight)->toBeNull()
        ->and($product->fresh()->production_work_order_id)->toBeNull()
        ->and(ProductCycle::count())->toBe(0)->and(WorkOrderProduct::count())->toBe(0)
        ->and(History::count())->toBe(0)->and($order->fresh()->wc_id)->toBe(22);
});

it('requires unique verified catalogs rather than seeder IDs', function () {
    $catalog = new ProductionCatalog;
    expect($catalog->id('filling_center'))->toBe(22)->and($catalog->id('waiting_location'))->toBe(42);
    DB::table('work_centers')->insert(['id' => 25, 'name' => 'Filling']);
    expect(fn () => $catalog->id('filling_center'))->toThrow(ValidationException::class);
    config(['production.catalog_ids.filling_center' => 22]);
    expect($catalog->id('filling_center'))->toBe(22);
    config(['production.catalog_ids.filling_center' => 999]);
    expect(fn () => $catalog->id('filling_center'))->toThrow(ValidationException::class);
});

it('starts the Livewire form without selecting a product and clears selection after saving', function () {
    $this->actingAs(fillingActor());
    $order = fillingOrder();
    fillingProduct($order);
    $product = fillingProduct($order);
    Livewire::test(WorkOrderFillingEditor::class, ['workOrder' => $order])
        ->assertSet('selectedProductId', null)
        ->assertSee('Pendientes: 2')
        ->set('serialInput', $product->serial)->call('scanSerial')->assertHasNoErrors()
        ->assertSet('selectedProductId', $product->id)
        ->assertDispatched('filling-product-selected')
        ->set('currentProductData.weight', 2500)->call('saveAndNext')->assertHasNoErrors()
        ->assertSet('selectedProductId', null)->assertSet('serialInput', '')
        ->assertSet('currentProductData.weight', null)
        ->assertSee('Pendientes: 1')->assertDispatched('filling-saved');
});

it('clears an old selection when the next scan fails and ignores delayed scale readings', function () {
    $this->actingAs(fillingActor());
    $order = fillingOrder();
    $a = fillingProduct($order);
    $b = fillingProduct($order);
    Livewire::test(WorkOrderFillingEditor::class, ['workOrder' => $order])
        ->set('serialInput', $a->serial)->call('scanSerial')
        ->set('serialInput', $b->serial)->call('scanSerial')
        ->call('receiveScaleWeight', 2500, $a->id)->assertSet('currentProductData.weight', null)
        ->call('receiveScaleWeight', 2500, $b->id)->assertSet('currentProductData.weight', 2500)
        ->set('serialInput', 'Z9999999Z')->call('scanSerial')->assertHasErrors('serialInput')
        ->assertSet('selectedProductId', null)
        ->call('saveAndNext')->assertHasErrors('serialInput');
    expect($a->fresh()->filled)->toBeFalse()->and($b->fresh()->filled)->toBeFalse();
});

it('requires a new scan if input changes after identification and protects locked product identity', function () {
    $this->actingAs(fillingActor());
    $order = fillingOrder();
    $a = fillingProduct($order);
    $b = fillingProduct($order);
    $component = Livewire::test(WorkOrderFillingEditor::class, ['workOrder' => $order])
        ->set('serialInput', $a->serial)->call('scanSerial')
        ->set('serialInput', $b->serial)->set('currentProductData.weight', 2500)
        ->call('saveAndNext')->assertHasErrors('serialInput');
    expect(fn () => $component->set('selectedProductId', $b->id))->toThrow(CannotUpdateLockedPropertyException::class);
    expect($a->fresh()->filled)->toBeFalse()->and($b->fresh()->filled)->toBeFalse();
});
