<?php

use App\Filament\Manager\Resources\InvoiceResource;
use App\Livewire\WorkOrderDistributionEditor;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceSelectiveVerification;
use App\Services\QuickBooksReadGateway;
use App\Services\QuickBooksService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

require_once __DIR__.'/../Support/ProductionDatabase.php';
require_once __DIR__.'/../Support/InvoiceDatabase.php';
uses(TestCase::class);

beforeEach(function () {
    setupTraceabilityDatabase();
    phaseOneUp();
    invoiceIntegrationUp();
    DB::table('wo_status')->insert([['id' => 13, 'name' => 'inProcess'], ['id' => 16, 'name' => 'Ready']]);
    DB::table('locations')->insert(['id' => 44, 'name' => 'Stock']);
    DB::table('status')->insert(['id' => 56, 'name' => 'Ready']);
    (require database_path('migrations/2026_10_13_130000_add_distribution_location.php'))->up();
    (require database_path('migrations/2026_10_13_130100_add_distribution_selection_snapshot.php'))->up();
    config(['permission.cache.store' => 'array', 'production.catalog_ids' => []]);
    app(PermissionRegistrar::class)->initializeCache();
    foreach (['Worker', 'Manager', 'Admin', 'Customer'] as $role) {
        Role::create(['name' => $role, 'guard_name' => 'web']);
    }
    $this->actor = User::findOrFail(1);
    $this->actor->assignRole('Worker');
    $this->actingAs($this->actor);
    $this->order = traceOrder(23);
    $this->order->update(['status_id' => 13]);
    DB::table('wo_ass')->insert(['work_order_id' => $this->order->id, 'asset_id' => 31, 'quantity' => 1]);
    $customer = Customer::create(['quickbooks_id' => '10', 'display_name' => 'Print test', 'user_id' => $this->actor->id]);
    $this->invoice = Invoice::create(['quickbooks_id' => '900', 'customer_id' => $customer->id,
        'invoice_number' => '0001-A', 'total' => 10, 'balance' => 10, 'status' => 'open', 'issued_at' => '2026-10-22',
        'operation_type' => 'distribution', 'work_order_id' => $this->order->id,
        'operation_snapshot' => ['requirements' => [31 => 1]]]);
    Http::preventStrayRequests();
    $qb = Mockery::mock(QuickBooksService::class);
    $qb->shouldNotReceive('ds');
    $this->instance(QuickBooksService::class, $qb);
    $this->gateway = Mockery::mock(QuickBooksReadGateway::class);
    $this->instance(QuickBooksReadGateway::class, $this->gateway);
});

afterEach(fn () => DB::disconnect('sqlite'));

it('shows Print Invoice and serves the original PDF for distribution roles without mutations', function ($role) {
    $this->actor->syncRoles($role);
    $url = route('invoices.pdf', ['invoice' => $this->invoice, 'work_order' => $this->order->id]);
    $before = app(InvoiceSelectiveVerification::class)->snapshot('900');
    $assets = DB::table('asset')->orderBy('id')->get()->toArray();
    Livewire::test(WorkOrderDistributionEditor::class, ['workOrder' => $this->order])
        ->assertSee('Print Invoice')->assertSeeHtml($url)->assertSeeHtml('target="_blank"');
    $this->gateway->shouldReceive('pdf')->once()->with(Mockery::on(fn ($invoice) => $invoice->id === $this->invoice->id))
        ->andReturn('%PDF-1.4 original QuickBooks bytes');
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename="invoice-'.$this->invoice->id.'.pdf"')
        ->assertContent('%PDF-1.4 original QuickBooks bytes');
    expect(app(InvoiceSelectiveVerification::class)->snapshot('900'))->toBe($before)
        ->and(DB::table('asset')->orderBy('id')->get()->toArray())->toEqual($assets)
        ->and(DB::table('invoice_events')->count())->toBe(0)->and(DB::table('history')->count())->toBe(0);
    Http::assertNothingSent();
})->with(['Worker', 'Manager', 'Admin']);

it('does not grant Workers general invoice or financial permissions', function () {
    $this->gateway->shouldNotReceive('pdf');
    foreach (['view', 'classify', 'update', 'delete'] as $ability) {
        expect(Gate::allows($ability, $this->invoice))->toBeFalse();
    }
    expect(Gate::allows('viewAny', Invoice::class))->toBeFalse()
        ->and(Gate::allows('create', Invoice::class))->toBeFalse()
        ->and(InvoiceResource::canAccess())->toBeFalse();
    $this->get(route('invoices.pdf', $this->invoice))->assertForbidden();
});

it('rejects mismatched inactive and nonDistribution contexts before fetching a PDF', function ($case) {
    $this->gateway->shouldNotReceive('pdf');
    $order = $this->order;
    if ($case === 'mismatched') {
        $order = traceOrder(23);
        $order->update(['status_id' => 13]);
    } elseif ($case === 'inactive') {
        $order->update(['status_id' => 16]);
    } elseif ($case === 'wrong_type') {
        $order->update(['type_id' => 17]);
    } else {
        $this->invoice->update(['operation_type' => 'installation']);
    }
    $this->get(route('invoices.pdf', ['invoice' => $this->invoice, 'work_order' => $order->id]))->assertForbidden();
})->with(['mismatched', 'inactive', 'wrong_type', 'wrong_invoice_operation']);

it('does not show a print button without a linked Invoice', function () {
    $order = traceOrder(23);
    $order->update(['status_id' => 13]);
    DB::table('wo_ass')->insert(['work_order_id' => $order->id, 'asset_id' => 31, 'quantity' => 1]);
    Livewire::test(WorkOrderDistributionEditor::class, ['workOrder' => $order])->assertDontSee('Print Invoice');
});

it('preserves existing Admin Manager and Customer invoice PDF permissions', function ($role) {
    $this->actor->syncRoles($role);
    $this->gateway->shouldReceive('pdf')->once()->andReturn('%PDF-1.4 original');
    $this->get(route('invoices.pdf', $this->invoice))->assertOk()->assertContent('%PDF-1.4 original');
    if ($role === 'Customer') {
        $other = Customer::create(['quickbooks_id' => '20', 'display_name' => 'Other']);
        $this->invoice->update(['customer_id' => $other->id]);
        $this->get(route('invoices.pdf', $this->invoice))->assertForbidden();
    }
})->with(['Admin', 'Manager', 'Customer']);

it('shows a clear QuickBooks error without changing the Invoice or Work Order', function () {
    $before = app(InvoiceSelectiveVerification::class)->snapshot('900');
    $this->gateway->shouldReceive('pdf')->once()->andThrow(new RuntimeException('Upstream unavailable'));
    $this->get(route('invoices.pdf', ['invoice' => $this->invoice, 'work_order' => $this->order->id]))
        ->assertStatus(502)->assertSee('Unable to download invoice PDF');
    expect(app(InvoiceSelectiveVerification::class)->snapshot('900'))->toBe($before);
});

it('rejects invalid or missing Work Orders without calling QuickBooks', function () {
    $this->gateway->shouldNotReceive('pdf');
    $this->getJson(route('invoices.pdf', ['invoice' => $this->invoice, 'work_order' => 'invalid']))->assertUnprocessable();
    $this->get(route('invoices.pdf', ['invoice' => $this->invoice, 'work_order' => 999999]))->assertNotFound();
});
