<?php

use App\Filament\Pages\Distribution;
use App\Livewire\WorkOrderDistributionEditor;
use App\Models\History;
use App\Models\ProductCycle;
use App\Models\Products;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderProduct;
use App\Services\DistributionSelectionService;
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

function distributionActor(bool $allowed = true): User
{
    $user = Mockery::mock(User::class)->makePartial();
    $user->forceFill(['id' => 1, 'name' => 'Distributor']);
    $user->shouldReceive('hasRole')->andReturn($allowed);

    return $user;
}

function distributionOrder(array $requirements = [31 => 2, 32 => 1]): WorkOrder
{
    $order = traceOrder(23);
    $order->update(['status_id' => 13]);
    foreach ($requirements as $asset => $quantity) {
        DB::table('wo_ass')->insert(['work_order_id' => $order->id, 'asset_id' => $asset, 'quantity' => $quantity]);
    }

    return $order->fresh();
}

function distributionStock(int $asset = 31, array $attributes = []): Products
{
    return traceProduct(null, array_merge(['asset_id' => $asset, 'assambled' => true,
        'location_id' => 44, 'status_id' => 56], $attributes));
}

beforeEach(function () {
    setupTraceabilityDatabase();
    phaseOneUp();
    config(['production.catalog_ids' => []]);
    DB::table('wo_status')->insert([['id' => 13, 'name' => 'inProcess'], ['id' => 16, 'name' => 'Ready']]);
    DB::table('locations')->insert([['id' => 44, 'name' => 'Stock'], ['id' => 45, 'name' => 'Recycle'], ['id' => 46, 'name' => 'Review']]);
    DB::table('status')->insert([['id' => 55, 'name' => 'Recycled'], ['id' => 56, 'name' => 'Ready']]);
    (require database_path('migrations/2026_10_13_130000_add_distribution_location.php'))->up();
    (require database_path('migrations/2026_10_13_130100_add_distribution_selection_snapshot.php'))->up();
});

it('reserves the exact serial without moving Stock or changing quantities and prevents duplicates', function () {
    $order = distributionOrder();
    $unused = distributionStock();
    $chosen = distributionStock();
    $before = $chosen->getRawOriginal();
    $service = app(DistributionSelectionService::class);
    $association = $service->reserve($order->id, 31, ' '.strtolower($chosen->serial), distributionActor());
    expect($association->product_id)->toBe($chosen->id)->and($association->state)->toBe('reserved')
        ->and($chosen->fresh()->getRawOriginal())->toBe($before)
        ->and($unused->fresh()->activeAssignment)->toBeNull()
        ->and($service->available()->where('asset_id', 31)->count())->toBe(1)
        ->and($service->requirements($order))->toBe([31 => 2, 32 => 1]);
    expect(fn () => $service->reserve($order->id, 31, $chosen->serial, distributionActor()))->toThrow(ValidationException::class);
    expect($service->reservations($order)->count())->toBe(1);
});

it('rejects unknown duplicate foreign model unqualified recycled and operationally assigned tubes', function () {
    $order = distributionOrder();
    $service = app(DistributionSelectionService::class);
    $invalid = [distributionStock(32), distributionStock(31, ['qualified' => false]),
        distributionStock(31, ['location_id' => 45, 'status_id' => 55]),
        distributionStock(31, ['location_id' => 46]), distributionStock(31, ['filled' => false]),
        distributionStock(31, ['assambled' => false]), distributionStock(31, ['quality_date' => null])];
    $active = distributionStock();
    $other = traceOrder(22);
    $association = traceAssociation($active, $other, 'review');
    $association->update(['state' => 'active']);
    $invalid[] = $active;
    $duplicate = distributionStock();
    distributionStock(31, ['serial' => $duplicate->serial]);
    $invalid[] = $duplicate;
    foreach ($invalid as $product) {
        expect(fn () => $service->reserve($order->id, 31, $product->serial, distributionActor()))->toThrow(ValidationException::class);
    }
    expect(fn () => $service->reserve($order->id, 31, 'UNKNOWN', distributionActor()))->toThrow(ValidationException::class);
    expect(WorkOrderProduct::where('purpose', 'distribution')->count())->toBe(0);
});

it('blocks another distribution from reserving the same tube and enforces the requested quantity', function () {
    $a = distributionOrder([31 => 1]);
    $b = distributionOrder([31 => 1]);
    $product = distributionStock();
    $extra = distributionStock();
    $service = app(DistributionSelectionService::class);
    $service->reserve($a->id, 31, $product->serial, distributionActor());
    expect(fn () => $service->reserve($b->id, 31, $product->serial, distributionActor()))->toThrow(ValidationException::class);
    expect(fn () => $service->reserve($a->id, 31, $extra->serial, distributionActor()))->toThrow(ValidationException::class);
    expect($service->reservations($a)->count())->toBe(1)->and($service->reservations($b)->count())->toBe(0);
});

it('releases a mistaken selection without deleting history and permits scanning it again', function () {
    $order = distributionOrder([31 => 1]);
    $product = distributionStock();
    $service = app(DistributionSelectionService::class);
    $selection = $service->reserve($order->id, 31, $product->serial, distributionActor());
    $service->remove($order->id, $selection->id, distributionActor());
    expect($selection->fresh()->state)->toBe('released')->and($service->reservations($order)->count())->toBe(0)
        ->and($service->available()->whereKey($product->id)->exists())->toBeTrue()
        ->and(Products::count())->toBe(1)->and(History::count())->toBe(2);
    $released = $selection->fresh();
    $again = $service->reserve($order->id, 31, $product->serial, distributionActor());
    expect($again->id)->toBe($selection->id)->and(History::count())->toBe(3);
    expect(fn () => $released->update(['distribution_serial' => 'FORGED']))->toThrow(LogicException::class);
});

it('confirms only the selected tubes after exact model counts and leaves unselected Stock untouched', function () {
    $order = distributionOrder();
    $unused = distributionStock();
    $a = distributionStock();
    $b = distributionStock();
    $c = distributionStock(32);
    $service = app(DistributionSelectionService::class);
    $service->reserve($order->id, 31, $a->serial, distributionActor());
    expect(fn () => $service->confirm($order->id, distributionActor()))->toThrow(ValidationException::class);
    $service->reserve($order->id, 31, $b->serial, distributionActor());
    $service->reserve($order->id, 32, $c->serial, distributionActor());
    $confirmed = $service->confirm($order->id, distributionActor());
    $location = DB::table('locations')->where('name', 'Distribution')->value('id');
    foreach ([$a, $b, $c] as $product) {
        expect($product->fresh()->location_id)->toBe($location)->and($product->fresh()->work_order_id)->toBe($order->id)
            ->and($product->fresh()->activeAssignment->work_order_product_id)->toBeNull();
    }
    expect($confirmed->status_id)->toBe(16)->and($unused->fresh()->location_id)->toBe(44)
        ->and(WorkOrderProduct::where('state', 'completed')->count())->toBe(3)
        ->and(History::where('process', 'Distribution confirmed')->pluck('metadata')->map(fn ($data) => $data['serial'])->all())->toBe([$a->serial, $b->serial, $c->serial]);
    expect(fn () => $service->confirm($order->id, distributionActor()))->toThrow(ValidationException::class);
});

it('revalidates modified quality location model quantity and assignments before confirmation', function ($field, $value) {
    $order = distributionOrder([31 => 1]);
    $product = distributionStock();
    $service = app(DistributionSelectionService::class);
    $service->reserve($order->id, 31, $product->serial, distributionActor());
    $product->update([$field => $value]);
    expect(fn () => $service->confirm($order->id, distributionActor()))->toThrow(ValidationException::class);
    expect($order->fresh()->status_id)->toBe(13)->and($service->reservations($order)->count())->toBe(1);
})->with([['qualified', false], ['location_id', 45], ['asset_id', 32], ['status_id', 55]]);

it('rejects changed requested quantities without replacing the selected serials', function () {
    $order = distributionOrder([31 => 1]);
    $product = distributionStock();
    $service = app(DistributionSelectionService::class);
    $service->reserve($order->id, 31, $product->serial, distributionActor());
    DB::table('wo_ass')->where('work_order_id', $order->id)->update(['quantity' => 2]);
    expect(fn () => $service->confirm($order->id, distributionActor()))->toThrow(ValidationException::class);
    expect($product->fresh()->location_id)->toBe(44);
});

it('calculates shortage from available stock plus own selections and creates a linked production order once', function () {
    $order = distributionOrder([31 => 4]);
    $product = distributionStock();
    distributionStock();
    distributionStock(31, ['qualified' => false]);
    $service = app(DistributionSelectionService::class);
    $service->reserve($order->id, 31, $product->serial, distributionActor());
    $production = $service->createShortage($order->id, 31, distributionActor());
    expect($production->quant)->toBe(2)->and($production->type_id)->toBe(17)
        ->and($production->status_id)->toBe(11)->and($production->for)->toBe($order->id)
        ->and($order->fresh()->status_id)->toBe(13)->and(Products::count())->toBe(3);
    expect(fn () => $service->createShortage($order->id, 31, distributionActor()))->toThrow(ValidationException::class);
});

it('authorizes each action and rejects another order selection', function () {
    $order = distributionOrder([31 => 1]);
    $other = distributionOrder([31 => 1]);
    $product = distributionStock();
    $service = app(DistributionSelectionService::class);
    $selected = $service->reserve($order->id, 31, $product->serial, distributionActor());
    expect(fn () => $service->remove($other->id, $selected->id, distributionActor()))->toThrow(ValidationException::class);
    foreach ([fn () => $service->reserve($other->id, 31, $product->serial, distributionActor(false)),
        fn () => $service->remove($order->id, $selected->id, distributionActor(false)),
        fn () => $service->confirm($order->id, distributionActor(false)),
        fn () => $service->createShortage($order->id, 31, distributionActor(false))] as $action) {
        expect($action)->toThrow(HttpException::class);
    }
});

it('rolls reservations and confirmation back completely on audit failure', function () {
    $order = distributionOrder([31 => 1]);
    $product = distributionStock();
    $service = app(DistributionSelectionService::class);
    DB::statement('CREATE TRIGGER fail_distribution BEFORE INSERT ON history BEGIN SELECT RAISE(ABORT, "audit failed"); END');
    expect(fn () => $service->reserve($order->id, 31, $product->serial, distributionActor()))->toThrow(QueryException::class);
    expect(WorkOrderProduct::count())->toBe(0)->and($product->fresh()->activeAssignment)->toBeNull();
    DB::statement('DROP TRIGGER fail_distribution');
    $selection = $service->reserve($order->id, 31, $product->serial, distributionActor());
    DB::statement('CREATE TRIGGER fail_confirmation BEFORE INSERT ON history BEGIN SELECT RAISE(ABORT, "audit failed"); END');
    expect(fn () => $service->confirm($order->id, distributionActor()))->toThrow(QueryException::class);
    expect($selection->fresh()->state)->toBe('reserved')->and($product->fresh()->location_id)->toBe(44)
        ->and($order->fresh()->status_id)->toBe(13)->and($product->fresh()->activeAssignment->work_order_product_id)->toBe($selection->id);
});

it('renders without mutations and scans removes and confirms through Livewire', function () {
    $order = distributionOrder([31 => 1]);
    $product = distributionStock();
    $this->actingAs(distributionActor());
    $before = $order->getRawOriginal();
    $editor = Livewire::test(WorkOrderDistributionEditor::class, ['workOrder' => $order])
        ->assertViewHas('complete', false)->assertSee('Model A');
    expect($order->fresh()->getRawOriginal())->toBe($before)->and(History::count())->toBe(0);
    $editor->set('serialInput', $product->serial)->call('scanSerial')->assertHasNoErrors()->assertViewHas('complete', true)->assertSet('serialInput', '');
    $selection = WorkOrderProduct::first();
    $editor->call('removeSelection', $selection->id)->assertViewHas('complete', false)
        ->set('serialInput', $product->serial)->call('scanSerial')->call('saveAndNext')->assertHasNoErrors()->assertViewHas('open', false);
    $editor->assertSee($product->serial)->assertViewHas('complete', true);
    expect($order->fresh()->status_id)->toBe(16);
});

it('preserves the scanned snapshot and rejects a changed or duplicated serial at confirmation', function () {
    $order = distributionOrder([31 => 1]);
    $product = distributionStock();
    $service = app(DistributionSelectionService::class);
    $selection = $service->reserve($order->id, 31, $product->serial, distributionActor());
    expect(fn () => $selection->update(['distribution_serial' => 'FORGED']))->toThrow(LogicException::class);
    $old = $product->serial;
    $product->update(['serial' => 'A9999999B']);
    expect(fn () => $service->confirm($order->id, distributionActor()))->toThrow(ValidationException::class);
    $product->update(['serial' => $old]);
    distributionStock(31, ['serial' => $old]);
    expect(fn () => $service->confirm($order->id, distributionActor()))->toThrow(ValidationException::class);
});

it('requires the latest cycle to approve quality and records that exact cycle in the snapshot', function () {
    $order = distributionOrder([31 => 1]);
    $product = distributionStock();
    $origin = traceOrder();
    $association = traceAssociation($product, $origin);
    $association->update(['state' => 'completed']);
    $approved = ProductCycle::create(['product_id' => $product->id, 'work_order_product_id' => $association->id,
        'cycle_number' => 1, 'source' => 'production', 'asset_id' => 31, 'fill_completed' => true,
        'quality_completed' => true, 'quality_result' => 'approved', 'completed_at' => now()]);
    $service = app(DistributionSelectionService::class);
    $selection = $service->reserve($order->id, 31, $product->serial, distributionActor());
    expect($selection->distribution_cycle_id)->toBe($approved->id);
    $service->remove($order->id, $selection->id, distributionActor());
    ProductCycle::create(['product_id' => $product->id, 'work_order_product_id' => $association->id,
        'cycle_number' => 2, 'source' => 'repair', 'asset_id' => 31, 'fill_completed' => true, 'quality_completed' => false]);
    expect($service->available()->whereKey($product->id)->exists())->toBeFalse();
    expect(fn () => $service->reserve($order->id, 31, $product->serial, distributionActor()))->toThrow(ValidationException::class);
});

it('adopts the distribution catalog and blocks rollback of stored selections', function () {
    $location = require database_path('migrations/2026_10_13_130000_add_distribution_location.php');
    $location->up();
    expect(DB::table('locations')->where('name', 'Distribution')->count())->toBe(1);
    expect(fn () => $location->down())->toThrow(RuntimeException::class);
    $snapshot = require database_path('migrations/2026_10_13_130100_add_distribution_selection_snapshot.php');
    $snapshot->down();
    $snapshot->up();
    $order = distributionOrder([31 => 1]);
    $product = distributionStock();
    app(DistributionSelectionService::class)->reserve($order->id, 31, $product->serial, distributionActor());
    expect(fn () => $snapshot->down())->toThrow(RuntimeException::class);
});

it('rejects empty duplicate or nonpositive requirement rows and nonoperating orders', function () {
    $order = distributionOrder([]);
    expect(fn () => app(DistributionSelectionService::class)->requirements($order))->toThrow(ValidationException::class);
    DB::table('wo_ass')->insert(['work_order_id' => $order->id, 'asset_id' => 31, 'quantity' => 0]);
    expect(fn () => app(DistributionSelectionService::class)->requirements($order))->toThrow(ValidationException::class);
    DB::table('wo_ass')->where('work_order_id', $order->id)->update(['quantity' => 1]);
    DB::table('wo_ass')->insert(['work_order_id' => $order->id, 'asset_id' => 31, 'quantity' => 1]);
    expect(fn () => app(DistributionSelectionService::class)->requirements($order))->toThrow(ValidationException::class);
    $order->update(['status_id' => 11]);
    expect(fn () => app(DistributionSelectionService::class)->reserve($order->id, 31, distributionStock()->serial, distributionActor()))->toThrow(ValidationException::class);
});

it('renders the Worker page and scopes camera selection to its chosen distribution', function () {
    $order = distributionOrder([31 => 1]);
    $product = distributionStock();
    config(['permission.cache.store' => 'array']);
    app('cache')->setDefaultDriver('array');
    app(PermissionRegistrar::class)->initializeCache();
    (require collect(glob(database_path('migrations/*create_permission_tables.php')))->first())->up();
    Role::create(['name' => 'Worker', 'guard_name' => 'web']);
    $user = User::findOrFail(1);
    $user->assignRole('Worker');
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('worker'));
    Livewire::test(Distribution::class)->assertSee($order->name)
        ->call('openScanner', $order->id)->assertSet('scanOrderId', $order->id)
        ->call('setCode', $product->serial)
        ->assertDispatched('distribution-camera-scanned', orderId: $order->id, code: $product->serial)
        ->assertSet('scanOrderId', null);
    Livewire::test(WorkOrderDistributionEditor::class, ['workOrder' => $order])
        ->call('scanCamera', $order->id + 1, $product->serial)->assertViewHas('complete', false)
        ->call('scanCamera', $order->id, $product->serial)->assertHasNoErrors()->assertViewHas('complete', true);
    expect(WorkOrderProduct::where('state', 'reserved')->count())->toBe(1);
});
