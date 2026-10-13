<?php

use App\Filament\Shared\Resources\InventoryResource\Widgets\ProductionChart;
use App\Filament\Shared\Resources\InventoryResource\Widgets\QualityChart;
use App\Filament\Shared\Resources\InventoryResource\Widgets\StockChart;
use App\Filament\Shared\Resources\InventoryResource\Widgets\WaitingChart;
use App\Filament\Shared\Resources\WorkOrderResource\Pages\ListWorkOrders;
use App\Livewire\WorkOrderAssembleEditor;
use App\Models\History;
use App\Models\ProductCycle;
use App\Models\Products;
use App\Models\Serial;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\DistributionSelectionService;
use App\Services\ProductionAssemblyService;
use App\Services\ProductionFillingService;
use App\Services\ProductionQualityService;
use App\Services\ProductionWaitingService;
use App\Services\ReviewInspectionService;
use App\Services\ReviewWorkOrderService;
use App\Services\WorkOrderLifecycleService;
use Carbon\Carbon;
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

function workflowActor(bool $administrative = true, bool $operator = true): User
{
    $user = Mockery::mock(User::class)->makePartial();
    $user->forceFill(['id' => 1, 'name' => 'Workflow operator']);
    $user->shouldReceive('hasRole')->with(['Admin', 'Manager'])->andReturn($administrative);
    $user->shouldReceive('hasRole')->with(['Admin', 'Manager', 'Worker'])->andReturn($operator);

    return $user;
}

function workflowProduction(int $quantity = 3): WorkOrder
{
    $order = new WorkOrder;
    $order->forceFill(['type_id' => 17, 'asset_id' => 31, 'quant' => $quantity, 'date' => now()->toDateString()])->save();
    app(WorkOrderLifecycleService::class)->approve($order->id, workflowActor());

    return app(WorkOrderLifecycleService::class)->start($order->id, workflowActor());
}

function workflowAssembly(WorkOrder $order): array
{
    $products = $order->products()->orderBy('id')->get();
    foreach ($products as $product) {
        $serial = Serial::where('status', Serial::STATUS_RESERVED)->orderByDesc('id')->firstOrFail();
        app(ProductionAssemblyService::class)->assemble($order->id, $product->id, $serial->serial, true, workflowActor());
    }

    return $products->map->fresh()->all();
}

beforeEach(function () {
    setupTraceabilityDatabase();
    phaseOneUp();
    config(['production.catalog_ids' => []]);
    DB::table('wo_status')->insert([['id' => 12, 'name' => 'Approved'], ['id' => 13, 'name' => 'inProcess'], ['id' => 16, 'name' => 'Ready']]);
    DB::table('work_centers')->insert([['id' => 21, 'name' => 'Assemble'], ['id' => 22, 'name' => 'Fill'], ['id' => 23, 'name' => 'Waiting'], ['id' => 24, 'name' => 'Quality']]);
    DB::table('locations')->insert([['id' => 41, 'name' => 'Production'], ['id' => 42, 'name' => 'Waiting'], ['id' => 43, 'name' => 'Quality'], ['id' => 44, 'name' => 'Stock'], ['id' => 45, 'name' => 'Recycle']]);
    DB::table('status')->insert([['id' => 53, 'name' => 'inProcess'], ['id' => 55, 'name' => 'Recycled'], ['id' => 56, 'name' => 'Ready']]);
    phaseFourUp();
    foreach (['2026_10_13_110000_add_review_location.php', '2026_10_13_120000_allow_automatic_history_events.php',
        '2026_10_13_130000_add_distribution_location.php', '2026_10_13_130100_add_distribution_selection_snapshot.php'] as $migration) {
        (require database_path('migrations/'.$migration))->up();
    }
    $this->travelTo(Carbon::parse('2026-10-13 23:59:00'));
});

it('completes production assembly filling review repair recycle and exact serial distribution with verified catalogs', function () {
    $order = workflowProduction();
    expect($order->status_id)->toBe(13)->and($order->wc_id)->toBe(21)
        ->and($order->products()->count())->toBe(3)
        ->and(Serial::where('status', Serial::STATUS_RESERVED)->count())->toBe(3);
    [$a, $b, $c] = workflowAssembly($order);
    $serials = [$a->serial, $b->serial, $c->serial];
    expect($order->fresh()->wc_id)->toBe(22);
    $fill = app(ProductionFillingService::class);
    foreach ([[$c, 2500], [$a, 2501], [$b, 2502]] as [$product, $weight]) {
        $fill->fill($order->id, $product->id, $product->serial, $weight, workflowActor());
    }
    $this->travelTo(Carbon::parse('2026-10-17 23:59:59'));
    expect(app(ProductionWaitingService::class)->advance($order->id))->toBeFalse();
    $this->travelTo(Carbon::parse('2026-10-18 00:00:00'));
    $this->artisan('workorders:move-after-5days')->assertExitCode(0);
    $quality = app(ProductionQualityService::class);
    $quality->complete($order->id, $a->id, $a->serial, 2500, workflowActor());
    foreach ([[$b, 2490], [$c, 2480]] as [$product, $weight]) {
        $measurement = $quality->measure($order->id, $product->id, $product->serial, $weight, workflowActor());
        $result = $quality->complete($order->id, $product->id, $product->serial, $weight, workflowActor(), $measurement);
    }
    $review = $result['review_order'];
    $reviews = app(ReviewWorkOrderService::class);
    expect($review->quant)->toBe(2)->and($order->fresh()->status_id)->toBe(16);
    $reviews->close($review->id, workflowActor());
    $reviews->approve($review->id, workflowActor());
    $reviews->start($review->id, workflowActor());
    $inspection = app(ReviewInspectionService::class);
    $inspection->inspect($review->id, $b->id, $b->serial, ['result' => 'repaired', 'diagnosis' => 'Fuga', 'repair_description' => 'Válvula sustituida'], workflowActor());
    $inspection->inspect($review->id, $c->id, $c->serial, ['result' => 'recycled', 'diagnosis' => 'Fisura', 'recycling_reason' => 'Tubo fisurado'], workflowActor(), true);
    $rejected = $b->cycles()->first()->getRawOriginal();
    $fill->fill($review->id, $b->id, $b->serial, 2503, workflowActor());
    $this->travelTo(Carbon::parse('2026-10-23 00:00:00'));
    $this->artisan('workorders:move-after-5days')->assertExitCode(0);
    $quality->complete($review->id, $b->id, $b->serial, 2505, workflowActor());
    expect($b->cycles()->count())->toBe(2)->and($b->cycles()->first()->getRawOriginal())->toBe($rejected)
        ->and($c->fresh()->status_id)->toBe(55)->and($c->cycles()->count())->toBe(1)
        ->and(Products::orderBy('id')->pluck('serial')->all())->toBe($serials)
        ->and(Products::where('production_work_order_id', $order->id)->count())->toBe(3);
    $distribution = new WorkOrder;
    $distribution->forceFill(['type_id' => 23, 'date' => now()->toDateString()])->save();
    DB::table('wo_ass')->insert(['work_order_id' => $distribution->id, 'asset_id' => 31, 'quantity' => 2]);
    app(WorkOrderLifecycleService::class)->approve($distribution->id, workflowActor());
    app(WorkOrderLifecycleService::class)->start($distribution->id, workflowActor());
    $selections = app(DistributionSelectionService::class);
    expect($selections->available()->count())->toBe(2);
    $selections->reserve($distribution->id, 31, $b->serial, workflowActor());
    expect(fn () => $selections->reserve($distribution->id, 31, $c->serial, workflowActor()))->toThrow(ValidationException::class);
    $selections->reserve($distribution->id, 31, $a->serial, workflowActor());
    $selections->confirm($distribution->id, workflowActor());
    expect($distribution->fresh()->status_id)->toBe(16)->and($selections->available()->count())->toBe(0)
        ->and($a->fresh()->work_order_id)->toBe($distribution->id)->and($b->fresh()->work_order_id)->toBe($distribution->id)
        ->and($c->fresh()->location_id)->toBe(45)
        ->and(Serial::where('status', Serial::STATUS_USED)->count())->toBe(3)
        ->and(ProductCycle::count())->toBe(4)
        ->and(History::where('process', 'Distribution confirmed')->count())->toBe(2)
        ->and(History::whereNull('user_id')->where('process', 'Waiting completed')->count())->toBe(4);
});

it('keeps assembly sequential and moves only after every current product is assembled', function () {
    $order = workflowProduction(2);
    $products = $order->products()->orderBy('id')->get();
    $serials = Serial::where('status', Serial::STATUS_RESERVED)->get();
    $assembly = app(ProductionAssemblyService::class);
    expect(fn () => $assembly->assemble($order->id, $products[1]->id, $serials[0]->serial, true, workflowActor()))->toThrow(ValidationException::class);
    expect(fn () => $assembly->assemble($order->id, $products[0]->id, '', false, workflowActor()))->toThrow(ValidationException::class);
    $assembly->assemble($order->id, $products[0]->id, $serials[0]->serial, true, workflowActor());
    expect($order->fresh()->wc_id)->toBe(21);
    $assembly->assemble($order->id, $products[1]->id, $serials[1]->serial, true, workflowActor());
    expect($order->fresh()->wc_id)->toBe(22);
});

it('runs the real assembly component into the filling station and protects the selected product', function () {
    $order = workflowProduction(1);
    $this->actingAs(workflowActor());
    $serial = Serial::where('status', Serial::STATUS_RESERVED)->first()->serial;
    Livewire::test(WorkOrderAssembleEditor::class, ['workOrder' => $order])
        ->set('assambled', true)->set('serialInput', $serial)->call('saveAndNext')->assertHasNoErrors();
    expect($order->fresh()->wc_id)->toBe(22)->and($order->products()->first()->serial)->toBe($serial);
});

it('creates a shortage production order then approves assembles and returns its exact tube to distribution', function () {
    $distribution = traceOrder(23);
    $distribution->update(['status_id' => 13]);
    DB::table('wo_ass')->insert(['work_order_id' => $distribution->id, 'asset_id' => 31, 'quantity' => 1]);
    $selections = app(DistributionSelectionService::class);
    $production = $selections->createShortage($distribution->id, 31, workflowActor());
    app(WorkOrderLifecycleService::class)->approve($production->id, workflowActor());
    app(WorkOrderLifecycleService::class)->start($production->id, workflowActor());
    [$product] = workflowAssembly($production->fresh());
    app(ProductionFillingService::class)->fill($production->id, $product->id, $product->serial, 2500, workflowActor());
    $this->travelTo(Carbon::parse('2026-10-18'));
    app(ProductionWaitingService::class)->advance($production->id);
    app(ProductionQualityService::class)->complete($production->id, $product->id, $product->serial, 2500, workflowActor());
    $selections->reserve($distribution->id, 31, $product->serial, workflowActor());
    $selections->confirm($distribution->id, workflowActor());
    expect($product->fresh()->production_work_order_id)->toBe($production->id)
        ->and($product->fresh()->work_order_id)->toBe($distribution->id);
});

it('restricts approval and start to administrators while allowing workers to assemble', function () {
    $order = traceOrder();
    $lifecycle = app(WorkOrderLifecycleService::class);
    expect(fn () => $lifecycle->approve($order->id, workflowActor(false)))->toThrow(HttpException::class);
    $order = workflowProduction(1);
    $product = $order->products()->first();
    $serial = Serial::where('status', Serial::STATUS_RESERVED)->first()->serial;
    expect(fn () => app(ProductionAssemblyService::class)->assemble($order->id, $product->id, $serial, true, workflowActor(false, false)))->toThrow(HttpException::class);
    app(ProductionAssemblyService::class)->assemble($order->id, $product->id, $serial, true, workflowActor(false, true));
    expect($product->fresh()->assambled)->toBeTrue();
});

it('rolls approval product creation and serial reservation back when a catalog is ambiguous', function () {
    $order = new WorkOrder;
    $order->forceFill(['type_id' => 17, 'asset_id' => 31, 'quant' => 2, 'date' => now()->toDateString()])->save();
    DB::table('locations')->insert(['name' => ' production ']);
    expect(fn () => app(WorkOrderLifecycleService::class)->approve($order->id, workflowActor()))->toThrow(ValidationException::class);
    expect($order->fresh()->status_id)->toBe(11)->and($order->fresh()->wc_id)->toBeNull()
        ->and(Products::count())->toBe(0)->and(Serial::count())->toBe(0);
});

it('rolls assembly identity serial use association and station advance back on audit failure', function () {
    $order = workflowProduction(1);
    $product = $order->products()->first();
    $serial = Serial::where('status', Serial::STATUS_RESERVED)->first();
    DB::statement('CREATE TRIGGER fail_assembly_audit BEFORE INSERT ON history BEGIN SELECT RAISE(ABORT, "audit failed"); END');
    expect(fn () => app(ProductionAssemblyService::class)->assemble($order->id, $product->id, $serial->serial, true, workflowActor()))->toThrow(QueryException::class);
    expect($product->fresh()->serial)->toBeNull()->and($product->fresh()->assambled)->toBeFalse()
        ->and($product->fresh()->production_work_order_id)->toBeNull()
        ->and($serial->fresh()->status)->toBe(Serial::STATUS_RESERVED)
        ->and($order->fresh()->wc_id)->toBe(21)->and($product->orderAssociations()->count())->toBe(0);
});

it('executes approval and start from the actual administrative Filament table with verified IDs', function () {
    config(['permission.cache.store' => 'array']);
    app('cache')->setDefaultDriver('array');
    app(PermissionRegistrar::class)->initializeCache();
    (require collect(glob(database_path('migrations/*create_permission_tables.php')))->first())->up();
    Role::create(['name' => 'Admin', 'guard_name' => 'web']);
    $actor = User::findOrFail(1);
    $actor->assignRole('Admin');
    $this->actingAs($actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $order = traceOrder();
    $page = Livewire::test(ListWorkOrders::class);
    $page->callTableAction('approved', $order)->assertHasNoErrors();
    expect($order->fresh()->status_id)->toBe(12)->and($order->products()->count())->toBe(2);
    $page->callTableAction('start', $order->fresh())->assertHasNoErrors();
    expect($order->fresh()->status_id)->toBe(13)->and($order->fresh()->wc_id)->toBe(21);
});

it('does not reopen a distribution already confirmed when linked production later completes quality', function () {
    $distribution = traceOrder(23);
    $distribution->update(['status_id' => 16]);
    $production = workflowProduction(1);
    $production->forceFill(['for' => $distribution->id])->save();
    [$product] = workflowAssembly($production);
    app(ProductionFillingService::class)->fill($production->id, $product->id, $product->serial, 2500, workflowActor());
    $this->travelTo(Carbon::parse('2026-10-18'));
    app(ProductionWaitingService::class)->advance($production->id);
    app(ProductionQualityService::class)->complete($production->id, $product->id, $product->serial, 2500, workflowActor());
    expect($distribution->fresh()->status_id)->toBe(16);
});

it('shows only available approved Stock in the inventory chart and restores it after removing a reservation', function () {
    $order = traceOrder(23);
    $order->update(['status_id' => 13]);
    DB::table('wo_ass')->insert(['work_order_id' => $order->id, 'asset_id' => 31, 'quantity' => 1]);
    $stock = traceProduct(null, ['assambled' => true, 'location_id' => 44, 'status_id' => 56]);
    traceProduct(null, ['assambled' => true, 'location_id' => 44, 'status_id' => 56, 'qualified' => false]);
    traceProduct(null, ['assambled' => true, 'location_id' => 45, 'status_id' => 55]);
    $chart = new class extends StockChart
    {
        public function snapshot(): array
        {
            return $this->getData();
        }
    };
    expect($chart->snapshot()['datasets'][0]['data']->all())->toBe([1]);
    $service = app(DistributionSelectionService::class);
    $selection = $service->reserve($order->id, 31, $stock->serial, workflowActor());
    expect($chart->snapshot()['datasets'][0]['data']->all())->toBe([]);
    $service->remove($order->id, $selection->id, workflowActor());
    expect($chart->snapshot()['datasets'][0]['data']->all())->toBe([1]);
    $service->reserve($order->id, 31, $stock->serial, workflowActor());
    $service->confirm($order->id, workflowActor());
    expect($chart->snapshot()['datasets'][0]['data']->all())->toBe([]);
});

it('reports production waiting and quality inventory using verified physical locations', function () {
    traceProduct(null, ['location_id' => 41]);
    traceProduct(null, ['location_id' => 42]);
    traceProduct(null, ['location_id' => 43]);
    $charts = [
        new class extends ProductionChart
        {
            public function snapshot(): array
            {
                return $this->getData();
            }
        },
        new class extends WaitingChart
        {
            public function snapshot(): array
            {
                return $this->getData();
            }
        },
        new class extends QualityChart
        {
            public function snapshot(): array
            {
                return $this->getData();
            }
        },
    ];
    foreach ($charts as $chart) {
        expect($chart->snapshot()['datasets'][0]['data']->all())->toBe([1]);
    }
});
