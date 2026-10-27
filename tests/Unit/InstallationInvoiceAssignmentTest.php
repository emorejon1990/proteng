<?php

use App\Filament\Shared\Resources\InstallationResource\Pages\CreateInstallation;
use App\Filament\Shared\Resources\WorkOrderResource\Pages\ViewWorkOrder;
use App\Models\Customer;
use App\Models\Installation;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\InstallationProgressService;
use App\Services\InstallationService;
use App\Services\InvoiceClassificationService;
use App\Services\InvoiceSyncService;
use App\Services\QuickBooksReadGateway;
use App\Services\QuickBooksService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

require_once __DIR__.'/../Support/ProductionDatabase.php';
require_once __DIR__.'/../Support/InvoiceDatabase.php';
uses(TestCase::class);

beforeEach(function () {
    setupTraceabilityDatabase();
    invoiceIntegrationUp();
    config(['permission.cache.store' => 'array', 'production.catalog_ids' => []]);
    app(PermissionRegistrar::class)->initializeCache();
    foreach (['Admin', 'Manager', 'Worker', 'Inst_Manager', 'Customer'] as $role) {
        Role::create(['name' => $role, 'guard_name' => 'web']);
    }
    $this->actor = User::findOrFail(1)->assignRole('Admin');
    $this->actingAs($this->actor);
    $manager = User::create(['name' => 'Installer', 'email' => 'installer@test.test', 'password' => 'test'])->assignRole('Inst_Manager');
    $worker = User::create(['name' => 'Worker', 'email' => 'worker@test.test', 'password' => 'test'])->assignRole('Worker');
    DB::table('wo_status')->insert([['id' => 13, 'name' => 'inProcess'], ['id' => 16, 'name' => 'Ready']]);
    Customer::create(['quickbooks_id' => '10', 'display_name' => 'Buyer']);
    $equipment = DB::table('equipment')->insertGetId(['brand' => 'Test', 'model' => 'Model', 'company' => 'PROTENG']);
    DB::table('equipment_installation_steps')->insert(['equipment_id' => $equipment, 'title' => 'Inspect', 'sort_order' => 1, 'is_required' => true]);
    $this->data = ['customer_id' => Customer::first()->id, 'equipment_id' => $equipment,
        'inst_manager_user_id' => $manager->id, 'worker_user_id' => $worker->id, 'status' => 'draft'];
    DB::table('asset')->where('id', 31)->update(['quickbooks_id' => '100']);
    Http::preventStrayRequests();
    $qb = Mockery::mock(QuickBooksService::class);
    $qb->shouldNotReceive('ds');
    $this->instance(QuickBooksService::class, $qb);
    $gateway = Mockery::mock(QuickBooksReadGateway::class);
    $gateway->shouldReceive('realm')->andReturn('test-realm');
    $gateway->shouldReceive('find')->with('Item', '100')->andReturn(['Id' => '100', 'Type' => 'Inventory', 'ParentRef' => '222']);
    $gateway->shouldReceive('find')->with('Item', '222')->andReturn(['Id' => '222', 'Type' => 'Category', 'Name' => 'Product']);
    $gateway->shouldNotReceive('page');
    $this->instance(QuickBooksReadGateway::class, $gateway);
});

afterEach(fn () => DB::disconnect('sqlite'));

function retrospectiveInvoice(string $id = '500', array $overrides = []): Invoice
{
    return app(InvoiceSyncService::class)->import(array_replace(['Id' => $id, 'DocNumber' => '1600', 'TotalAmt' => 100,
        'Balance' => 100, 'TxnDate' => '2026-10-27', 'CustomerRef' => '10',
        'Line' => [['Id' => '1', 'Amount' => 100, 'DetailType' => 'SalesItemLineDetail',
            'SalesItemLineDetail' => ['ItemRef' => '100', 'Qty' => 2]]]], $overrides), 'test-realm');
}

it('creates one WI order with the same customer and no Invoice and safely repeats ensureWorkOrder', function () {
    $installation = app(InstallationService::class)->create($this->data);
    $order = $installation->workOrder;
    expect($order->name)->toStartWith('WI')->and($order->isInstallationOrder())->toBeTrue()
        ->and($order->status_id)->toBe(11)->and($order->customer_quickbooks_id)->toBe('10')
        ->and($installation->invoice)->toBeNull()->and($installation->steps()->count())->toBe(1)
        ->and($installation->assignment()->count())->toBe(1);
    app(InstallationService::class)->ensureWorkOrder($installation);
    app(InstallationService::class)->ensureWorkOrder($installation->fresh());
    expect(WorkOrder::count())->toBe(1)->and(Invoice::count())->toBe(0)
        ->and(InvoiceEvent::where('event', 'Installation WorkOrder Created')->count())->toBe(1);
});

it('rolls back both records assignment and events when creation fails', function () {
    DB::statement('CREATE TRIGGER fail_installation_steps BEFORE INSERT ON installation_steps BEGIN SELECT RAISE(ABORT, "test failure"); END');
    expect(fn () => app(InstallationService::class)->create($this->data))->toThrow(QueryException::class);
    expect(Installation::count())->toBe(0)->and(WorkOrder::count())->toBe(0)
        ->and(DB::table('installation_assignments')->count())->toBe(0)->and(InvoiceEvent::count())->toBe(0);
});

it('moves New to inProcess to Ready without invoices products or duplicate completion events', function () {
    $installation = app(InstallationService::class)->create($this->data);
    $progress = app(InstallationProgressService::class);
    $progress->startIfNeeded($installation);
    expect($installation->status)->toBe('in_progress')->and($installation->workOrder->status_id)->toBe(13);
    expect($progress->completeIfAllDone($installation))->toBeFalse();
    $progress->markStep($installation->steps()->first(), true, 'Done');
    expect($progress->completeIfAllDone($installation))->toBeTrue()
        ->and($installation->status)->toBe('completed')->and($installation->workOrder->status_id)->toBe(16);
    $performed = $installation->performed_at->toISOString();
    $progress->completeIfAllDone($installation);
    $progress->startIfNeeded($installation);
    expect($installation->fresh()->performed_at->toISOString())->toBe($performed)
        ->and(InvoiceEvent::where('event', 'Installation WorkOrder Completed')->count())->toBe(1)
        ->and(InvoiceEvent::where('event', 'Installation WorkOrder Started')->count())->toBe(1)
        ->and(DB::table('products')->count())->toBe(0)->and(Invoice::count())->toBe(0);
});

it('leaves historical installations without Work Orders compatible and does not backfill them', function () {
    $installation = Installation::create($this->data + ['customer_quickbooks_id' => '10']);
    $progress = app(InstallationProgressService::class);
    $progress->startIfNeeded($installation);
    expect($progress->completeIfAllDone($installation))->toBeTrue()
        ->and($installation->work_order_id)->toBeNull()->and(WorkOrder::count())->toBe(0);
});

it('links a completed installation for authorized roles preserving operational records', function ($role) {
    $this->actor->syncRoles($role);
    $installation = app(InstallationService::class)->create($this->data);
    $installation->steps()->update(['is_done' => true]);
    app(InstallationProgressService::class)->completeIfAllDone($installation);
    $invoice = retrospectiveInvoice();
    $before = $installation->getRawOriginal();
    $order = $installation->workOrder->getRawOriginal();
    $steps = $installation->steps()->get()->map->getRawOriginal()->all();
    $assigned = app(InvoiceClassificationService::class)->assignToInstallation($installation->work_order_id, $invoice->id, $this->actor);
    expect($assigned->work_order_id)->toBe($installation->work_order_id)->and($assigned->quickbooks_id)->toBe('500')
        ->and($assigned->realm_id)->toBe('test-realm')->and($assigned->operation_snapshot['link_mode'])->toBe('existing_installation')
        ->and($installation->fresh()->getRawOriginal())->toBe($before)->and($installation->workOrder->fresh()->getRawOriginal())->toBe($order)
        ->and($installation->steps()->get()->map->getRawOriginal()->all())->toBe($steps)
        ->and($installation->invoice->id)->toBe($invoice->id)->and(WorkOrder::count())->toBe(1)
        ->and(DB::table('wo_ass')->count())->toBe(0);
})->with(['Admin', 'Manager']);

it('rejects Worker and installation manager assignment', function ($role) {
    $installation = app(InstallationService::class)->create($this->data);
    $invoice = retrospectiveInvoice();
    $this->actor->syncRoles($role);
    expect(fn () => app(InvoiceClassificationService::class)->assignToInstallation($installation->work_order_id, $invoice->id, $this->actor))->toThrow(HttpException::class);
    expect($invoice->fresh()->work_order_id)->toBeNull();
})->with(['Worker', 'Inst_Manager']);

it('rejects other customers sync errors void invoices and reused links', function ($case) {
    $installation = app(InstallationService::class)->create($this->data);
    $invoice = retrospectiveInvoice();
    if ($case === 'customer') {
        $customer = Customer::create(['quickbooks_id' => '20', 'display_name' => 'Other']);
        $invoice->update(['customer_id' => $customer->id]);
    } elseif ($case === 'void') {
        $invoice->update(['status' => 'void']);
    } elseif ($case === 'sync') {
        $invoice->update(['sync_status' => 'failed', 'sync_error' => 'Review']);
    } elseif ($case === 'review') {
        $invoice->update(['operation_status' => 'requires_review']);
    } elseif ($case === 'used_invoice') {
        $other = app(InstallationService::class)->create($this->data);
        app(InvoiceClassificationService::class)->assignToInstallation($other->work_order_id, $invoice->id, $this->actor);
    } else {
        $previous = retrospectiveInvoice('501');
        app(InvoiceClassificationService::class)->assignToInstallation($installation->work_order_id, $previous->id, $this->actor);
    }
    expect(fn () => app(InvoiceClassificationService::class)->assignToInstallation($installation->work_order_id, $invoice->id, $this->actor))->toThrow(ValidationException::class);
})->with(['customer', 'void', 'sync', 'review', 'used_invoice', 'used_order']);

it('preserves the link snapshot and completed installation through repeated and accounting-only imports', function () {
    $installation = app(InstallationService::class)->create($this->data);
    $installation->steps()->update(['is_done' => true]);
    app(InstallationProgressService::class)->completeIfAllDone($installation);
    $invoice = retrospectiveInvoice();
    $linked = app(InvoiceClassificationService::class)->assignToInstallation($installation->work_order_id, $invoice->id, $this->actor);
    $snapshot = $linked->operation_snapshot;
    $before = $installation->fresh()->getRawOriginal();
    $unchanged = retrospectiveInvoice();
    $paid = retrospectiveInvoice(overrides: ['Balance' => 0]);
    expect($unchanged->work_order_id)->toBe($installation->work_order_id)->and($paid->work_order_id)->toBe($installation->work_order_id)
        ->and($paid->operation_status)->toBe('created')->and($paid->operation_snapshot)->toBe($snapshot)
        ->and($installation->fresh()->getRawOriginal())->toBe($before)->and($installation->workOrder->status_id)->toBe(16);
});

it('keeps Distribution classification independent and does not create Installation records', function () {
    $invoice = retrospectiveInvoice();
    $order = app(InvoiceClassificationService::class)->classify($invoice->id, 'distribution', $this->actor);
    expect($order->type_id)->toBe(23)->and($order->name)->toStartWith('WD')->and(Installation::count())->toBe(0)
        ->and($order->workOrderAssets()->pluck('quantity', 'asset_id')->all())->toBe([31 => 2]);
});

it('creates through the existing Installations form without duplicating steps', function () {
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    Livewire\Livewire::test(CreateInstallation::class)
        ->fillForm($this->data)->call('create')->assertHasNoFormErrors();
    $installation = Installation::sole();
    expect(WorkOrder::count())->toBe(1)->and($installation->workOrder->name)->toStartWith('WI')
        ->and($installation->steps()->count())->toBe(1)->and($installation->assignment()->count())->toBe(1)
        ->and(Invoice::count())->toBe(0);
});

it('assigns from the existing Work Order detail action and hides a second assignment', function () {
    $installation = app(InstallationService::class)->create($this->data);
    $invoice = retrospectiveInvoice();
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    Livewire\Livewire::test(ViewWorkOrder::class, ['record' => $installation->work_order_id])
        ->assertActionVisible('assignInvoice')->callAction('assignInvoice', ['invoice_id' => $invoice->id])
        ->assertHasNoActionErrors()->assertActionHidden('assignInvoice')->assertSee('1600');
    expect($invoice->fresh()->work_order_id)->toBe($installation->work_order_id);
});

it('enforces unique links at database level as well as service row locks', function () {
    $installation = app(InstallationService::class)->create($this->data);
    $invoice = retrospectiveInvoice();
    app(InvoiceClassificationService::class)->assignToInstallation($installation->work_order_id, $invoice->id, $this->actor);
    $other = retrospectiveInvoice('501');
    expect(fn () => $other->update(['work_order_id' => $installation->work_order_id]))->toThrow(QueryException::class);
    expect(fn () => Installation::create($this->data + ['work_order_id' => $installation->work_order_id]))->toThrow(QueryException::class);
    expect(WorkOrder::count())->toBe(1)->and(Installation::count())->toBe(1);
});

it('rolls back completion if its audit event cannot be recorded', function () {
    $installation = app(InstallationService::class)->create($this->data);
    $installation->steps()->update(['is_done' => true]);
    DB::statement("CREATE TRIGGER fail_completion BEFORE INSERT ON invoice_events WHEN NEW.event = 'Installation WorkOrder Completed' BEGIN SELECT RAISE(ABORT, 'test failure'); END");
    expect(fn () => app(InstallationProgressService::class)->completeIfAllDone($installation))->toThrow(QueryException::class);
    expect($installation->fresh()->status)->toBe('draft')->and($installation->workOrder->status_id)->toBe(11);
});

it('links an already paid imported Product invoice without creating an operation', function () {
    $installation = app(InstallationService::class)->create($this->data);
    $invoice = retrospectiveInvoice(overrides: ['Balance' => 0]);
    $assigned = app(InvoiceClassificationService::class)->assignToInstallation($installation->work_order_id, $invoice->id, $this->actor);
    expect($assigned->status)->toBe('paid')->and($assigned->work_order_id)->toBe($installation->work_order_id)
        ->and(WorkOrder::count())->toBe(1)->and(DB::table('wo_ass')->count())->toBe(0);
});
