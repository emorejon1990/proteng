<?php

use App\Filament\Admin\Pages\InvoiceSyncEvents;
use App\Filament\Admin\Pages\QuickBooksSync;
use App\Filament\Manager\Resources\InvoiceResource\Pages\ListInvoices;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AssetSyncService;
use App\Services\CustomerSyncService;
use App\Services\DistributionSelectionService;
use App\Services\InvoiceClassificationService;
use App\Services\InvoiceDataException;
use App\Services\InvoiceDocumentService;
use App\Services\InvoiceSyncService;
use App\Services\QuickBooksReadGateway;
use App\Services\QuickBooksService;
use Filament\Notifications\Notification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use QuickBooksOnline\API\Data\IPPInvoice;
use QuickBooksOnline\API\Data\IPPReferenceType;
use QuickBooksOnline\API\DataService\DataService;
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
    config(['permission.cache.store' => 'array']);
    app(PermissionRegistrar::class)->initializeCache();
    foreach (['Admin', 'Manager', 'Customer', 'Worker', 'Inst_Manager'] as $role) {
        Role::create(['name' => $role, 'guard_name' => 'web']);
    }
    User::findOrFail(1)->assignRole('Manager');
    $this->actingAs(User::findOrFail(1));
    Customer::create(['quickbooks_id' => '10', 'display_name' => 'Buyer']);
    DB::table('asset')->where('id', 31)->update(['quickbooks_id' => '100']);
    $gateway = Mockery::mock(QuickBooksReadGateway::class);
    $gateway->shouldReceive('realm')->andReturn('realm-one');
    $gateway->shouldReceive('find')->with('Item', '100')->andReturn(['Id' => '100', 'Type' => 'Inventory']);
    $gateway->shouldReceive('find')->with('Item', '200')->andReturn(['Id' => '200', 'Type' => 'Service']);
    $gateway->shouldReceive('find')->with('Item', '300')->andReturn(['Id' => '300', 'Type' => 'NonInventory']);
    $this->instance(QuickBooksReadGateway::class, $gateway);
    $this->gateway = $gateway;
});
afterEach(function () {
    DB::disconnect('sqlite');
});
function invoicePayload(array $overrides = []): array
{
    return array_replace([
        'Id' => '500', 'DocNumber' => 'INV-500', 'CustomerRef' => ['value' => '10'],
        'TotalAmt' => '110.00', 'Balance' => '110.00', 'TxnDate' => '2026-10-13',
        'CurrencyRef' => ['value' => 'USD'], 'BillAddr' => ['Line1' => 'Billing street'], 'ShipAddr' => ['Line1' => 'Shipping street'],
        'Line' => [
            ['Id' => '1', 'Amount' => '100.00', 'DetailType' => 'SalesItemLineDetail', 'Description' => 'Tube', 'SalesItemLineDetail' => ['ItemRef' => ['value' => '100'], 'Qty' => 2, 'UnitPrice' => '50.00']],
            ['Id' => '2', 'Amount' => '10.00', 'DetailType' => 'SalesItemLineDetail', 'Description' => 'Labor', 'SalesItemLineDetail' => ['ItemRef' => ['value' => '200'], 'Qty' => 1, 'UnitPrice' => '10.00']],
            ['Amount' => '110.00', 'DetailType' => 'SubTotalLineDetail', 'SubTotalLineDetail' => []],
        ],
    ], $overrides);
}
function importInvoice(array $overrides = []): Invoice
{
    return app(InvoiceSyncService::class)->import(invoicePayload($overrides), 'realm-one');
}
function classifyInvoice(Invoice $invoice, string $type = 'distribution', array $data = []): WorkOrder
{
    return app(InvoiceClassificationService::class)->classify($invoice->id, $type, User::findOrFail(1), $data);
}

it('imports headers addresses and all line types idempotently', function () {
    $first = importInvoice();
    $second = importInvoice();
    expect($second->id)->toBe($first->id)->and(Invoice::count())->toBe(1)->and($second->lines()->count())->toBe(3)
        ->and($second->lines->first()->asset_id)->toBe(31)->and($second->lines[1]->asset_id)->toBeNull()
        ->and($second->shipping_address['Line1'])->toBe('Shipping street')->and($second->status)->toBe('open');
    expect(InvoiceEvent::where('event', 'Invoice Imported')->count())->toBe(1);
});
it('updates accounting data and replaces complete lines', function () {
    $invoice = importInvoice();
    $p = invoicePayload(['Balance' => '0']);
    array_pop($p['Line']);
    app(InvoiceSyncService::class)->import($p, 'realm-one');
    expect($invoice->fresh()->status)->toBe('paid')->and($invoice->lines()->count())->toBe(2);
});
it('preserves valid data on incomplete payloads or failed item reads', function () {
    $invoice = importInvoice();
    expect(fn () => importInvoice(['Line' => []]))->toThrow(RuntimeException::class);
    $this->gateway->shouldReceive('find')->with('Item', '999')->andThrow(new RuntimeException('Timeout'));
    $p = invoicePayload();
    $p['Line'][0]['SalesItemLineDetail']['ItemRef']['value'] = '999';
    expect(fn () => app(InvoiceSyncService::class)->import($p, 'realm-one'))->toThrow(RuntimeException::class);
    expect($invoice->fresh()->total)->toBe('110.00')->and($invoice->lines()->count())->toBe(3);
});
it('keeps unmapped physical items for review and prevents incomplete orders', function () {
    $p = invoicePayload();
    $p['Line'][0]['SalesItemLineDetail']['ItemRef']['value'] = '300';
    $invoice = app(InvoiceSyncService::class)->import($p, 'realm-one');
    expect($invoice->operation_status)->toBe('requires_review');
    expect(fn () => classifyInvoice($invoice))->toThrow(ValidationException::class);
    expect(WorkOrder::count())->toBe(0);
});
it('imports a missing customer without creating or emailing a user', function () {
    $this->gateway->shouldReceive('find')->with('Customer', '11')->andReturn(['Id' => '11', 'DisplayName' => 'New buyer']);
    $invoice = importInvoice(['CustomerRef' => ['value' => '11']]);
    expect($invoice->customer->display_name)->toBe('New buyer')->and(User::count())->toBe(1);
});
it('preserves customer addresses when absent and clears explicit empties', function () {
    $service = app(CustomerSyncService::class);
    $customer = $service->import(['Id' => '10', 'DisplayName' => 'Buyer', 'BillAddr' => ['Line1' => 'A'], 'ShipAddr' => ['Line5' => 'B']]);
    $service->import(['Id' => '10', 'DisplayName' => 'Buyer']);
    expect($customer->fresh()->billing_address)->toBe(['Line1' => 'A']);
    $service->import(['Id' => '10', 'DisplayName' => 'Buyer', 'BillAddr' => [], 'ShipAddr' => null]);
    expect($customer->fresh()->billing_address)->toBe([])->and($customer->fresh()->shipping_address)->toBeNull();
});
it('creates a New Distribution using physical requirements without serials', function () {
    $invoice = importInvoice();
    $order = classifyInvoice($invoice);
    expect($order->type_id)->toBe(23)->and($order->status_id)->toBe(11)->and($order->name)->toBe('WD1')
        ->and($order->workOrderAssets()->count())->toBe(1)->and($order->workOrderAssets->first()->quantity)->toBe(2)
        ->and(DB::table('products')->count())->toBe(0)->and(DB::table('serial')->count())->toBe(0)
        ->and($invoice->fresh()->workOrder->id)->toBe($order->id)->and($order->invoice->id)->toBe($invoice->id);
    expect(app(DistributionSelectionService::class)->requirements($order))->toBe([31 => 2]);
});
it('rejects duplicate classifications and type changes', function () {
    $invoice = importInvoice();
    classifyInvoice($invoice);
    expect(fn () => classifyInvoice($invoice))->toThrow(ValidationException::class);
    expect(fn () => classifyInvoice($invoice, 'installation'))->toThrow(ValidationException::class);
    expect(WorkOrder::count())->toBe(1);
});
it('rejects invalid physical quantities', function ($qty) {
    $p = invoicePayload();
    $p['Line'][0]['SalesItemLineDetail']['Qty'] = $qty;
    $invoice = app(InvoiceSyncService::class)->import($p, 'realm-one');
    expect(fn () => classifyInvoice($invoice))->toThrow(ValidationException::class);
    expect(WorkOrder::count())->toBe(0);
})->with([0, -1, 1.5, 2147483648]);
it('rolls back order requirements and classification on audit failure', function () {
    $invoice = importInvoice();
    DB::statement('CREATE TRIGGER fail_classification BEFORE INSERT ON invoice_events BEGIN SELECT RAISE(ABORT, "audit failed"); END');
    expect(fn () => classifyInvoice($invoice))->toThrow(QueryException::class);
    expect(WorkOrder::count())->toBe(0)->and(DB::table('wo_ass')->count())->toBe(0)->and($invoice->fresh()->operation_type)->toBeNull();
});
it('flags changed physical quantities but preserves the operation and classification', function () {
    $invoice = importInvoice();
    $order = classifyInvoice($invoice);
    $p = invoicePayload(['Balance' => '0']);
    $p['Line'][0]['SalesItemLineDetail']['Qty'] = 3;
    app(InvoiceSyncService::class)->import($p, 'realm-one');
    expect($invoice->fresh()->operation_status)->toBe('sync_conflict')->and($invoice->fresh()->operation_type)->toBe('distribution')
        ->and($order->workOrderAssets->first()->quantity)->toBe(2)->and($order->fresh()->status_id)->toBe(11);
});
it('updates payments without creating a physical sync conflict', function () {
    $invoice = importInvoice();
    classifyInvoice($invoice);
    importInvoice(['Balance' => '0']);
    expect($invoice->fresh()->operation_status)->toBe('created')->and($invoice->fresh()->balance)->toBe('0.00');
});
it('creates a linked Installation with one assignment and uncompleted template steps', function () {
    $equipment = DB::table('equipment')->insertGetId(['brand' => 'Brand', 'model' => 'Model', 'company' => 'PROTENG']);
    DB::table('equipment_installation_steps')->insert(['equipment_id' => $equipment, 'title' => 'Inspect', 'sort_order' => 1, 'is_required' => true]);
    $manager = User::create(['name' => 'Installer', 'email' => 'installer@example.test', 'password' => 'test']);
    $manager->assignRole('Inst_Manager');
    $worker = User::create(['name' => 'Worker', 'email' => 'worker@example.test', 'password' => 'test']);
    $worker->assignRole('Worker');
    $invoice = importInvoice();
    $order = classifyInvoice($invoice, 'installation', ['equipment_id' => $equipment, 'inst_manager_user_id' => $manager->id, 'worker_user_id' => $worker->id]);
    expect($order->type->name)->toBe('Installation')->and($order->status_id)->toBe(11)->and($order->installation->status)->toBe('draft')
        ->and($order->installation->steps()->count())->toBe(1)->and($order->installation->steps->first()->is_done)->toBeFalse()
        ->and($order->installation->assignment()->count())->toBe(1)->and($order->installation->invoice->id)->toBe($invoice->id)
        ->and($order->workOrderAssets->first()->quantity)->toBe(2);
});
it('requires explicit valid installation configuration', function () {
    $invoice = importInvoice();
    expect(fn () => classifyInvoice($invoice, 'installation'))->toThrow(ValidationException::class);
    expect(WorkOrder::count())->toBe(0);
});
it('denies Workers and Customers classification and enforces invoice ownership', function () {
    $invoice = importInvoice();
    $buyer = User::create(['name' => 'Buyer', 'email' => 'buyer@example.test', 'password' => 'test']);
    $buyer->assignRole('Customer');
    expect(Gate::forUser($buyer)->allows('view', $invoice))->toBeFalse();
    $invoice->customer->update(['user_id' => $buyer->id]);
    $buyer->unsetRelation('customer');
    expect(Gate::forUser($buyer)->allows('view', $invoice))->toBeTrue();
    expect(fn () => app(InvoiceClassificationService::class)->classify($invoice->id, 'distribution', $buyer))->toThrow(HttpException::class);
    expect(Gate::forUser(User::find(1))->allows('update', $invoice))->toBeFalse();
    expect(Gate::forUser(User::find(1))->allows('create', Invoice::class))->toBeFalse();
});
it('rejects legacy invoice creation without calling QuickBooks writes', function () {
    expect(fn () => app(InvoiceSyncService::class)->create(Customer::first(), []))->toThrow(LogicException::class);
});
it('serves authorized PDF downloads and denies unrelated customers before QuickBooks access', function () {
    $invoice = importInvoice();
    $this->gateway->shouldReceive('pdf')->once()->with(Mockery::on(fn ($v) => $v->id === $invoice->id))->andReturn('%PDF-1.4 test');
    $this->get(route('invoices.pdf', $invoice))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'test']);
    $other->assignRole('Customer');
    $this->actingAs($other)->get(route('invoices.pdf', $invoice))->assertForbidden();
});
it('returns a clear error if the invoice PDF fails', function () {
    $invoice = importInvoice();
    $this->gateway->shouldReceive('pdf')->andThrow(new RuntimeException('No PDF'));
    $this->get(route('invoices.pdf', $invoice))->assertStatus(502);
});
it('generates a packing slip from operation quantities with no service or financial fields', function () {
    $invoice = importInvoice();
    $order = classifyInvoice($invoice);
    $html = view('pdf.invoice-packing-slip', ['invoice' => $invoice->fresh(), 'order' => $order->load('workOrderAssets.asset')])->render();
    expect($html)->toContain('Model A', 'Shipping street', 'INV-500', 'Tube')->not->toContain('Labor', 'Unit Price', 'Taxes', 'Balance', '110.00');
    $pdf = app(InvoiceDocumentService::class)->packingSlip($invoice, User::find(1));
    expect($pdf)->toStartWith('%PDF-');
    $this->get(route('invoices.packing-slip', $invoice))->assertOk();
});
it('paginates all invoices and records failed synchronization without losing data', function () {
    User::find(1)->assignRole('Admin');
    $page = array_map(fn ($id) => invoicePayload(['Id' => (string) $id]), range(500, 599));
    $this->gateway->shouldReceive('page')->with('Invoice', 1)->once()->andReturn($page);
    $this->gateway->shouldReceive('page')->with('Invoice', 101)->once()->andReturn([invoicePayload(['Id' => '600'])]);
    app(InvoiceSyncService::class)->sync(User::find(1));
    expect(Invoice::count())->toBe(101);
});
it('blocks cross company synchronization', function () {
    importInvoice();
    expect(fn () => app(InvoiceSyncService::class)->import(invoicePayload(), 'another-realm'))->toThrow(RuntimeException::class);
});
it('normalizes SDK default nulls while preserving explicit payload empties', function () {
    expect(QuickBooksReadGateway::payload((object) ['Id' => '1', 'BillAddr' => null]))->toBe(['Id' => '1']);
    expect(QuickBooksReadGateway::payload(['Id' => '1', 'BillAddr' => null]))->toBe(['Id' => '1', 'BillAddr' => null]);
});

it('renders Manager and Admin invoices and classifies through Filament', function () {
    $invoice = importInvoice();
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('manager'));
    Livewire\Livewire::test(ListInvoices::class)
        ->assertCanSeeTableRecords([$invoice])->callTableAction('classify', $invoice, ['operation_type' => 'distribution'])->assertHasNoTableActionErrors();
    expect($invoice->fresh()->operation_status)->toBe('created');
    User::find(1)->assignRole('Admin');
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    Livewire\Livewire::test(ListInvoices::class)->assertCanSeeTableRecords([$invoice]);
});
it('marks voided invoices for review without deleting operations', function () {
    $invoice = importInvoice();
    $order = classifyInvoice($invoice);
    importInvoice(['PrivateNote' => 'Voided', 'TotalAmt' => '0', 'Balance' => '0']);
    expect($invoice->fresh()->status)->toBe('void')->and($invoice->fresh()->operation_status)->toBe('sync_conflict')
        ->and($order->fresh()->status_id)->toBe(11)->and(WorkOrder::count())->toBe(1);
});
it('stops on unavailable source verification and preserves linked operations', function () {
    $invoice = importInvoice();
    classifyInvoice($invoice);
    User::find(1)->assignRole('Admin');
    $this->gateway->shouldReceive('page')->with('Invoice', 1)->andReturn([]);
    $this->gateway->shouldReceive('find')->with('Invoice', '500')->andThrow(new RuntimeException('Unavailable'));
    expect(fn () => app(InvoiceSyncService::class)->sync(User::find(1)))->toThrow(RuntimeException::class);
    expect($invoice->fresh()->operation_status)->toBe('created')->and($invoice->fresh()->sync_status)->toBe('synced')
        ->and(WorkOrder::count())->toBe(1)->and($invoice->lines()->count())->toBe(3);
});
it('blocks synchronization by Managers and Customers on the server', function () {
    expect(fn () => app(InvoiceSyncService::class)->sync(User::find(1)))->toThrow(HttpException::class);
});
it('uses SDK read methods and checks SDK errors and invalid PDFs', function () {
    $ds = Mockery::mock(DataService::class);
    $qb = Mockery::mock(QuickBooksService::class);
    $qb->shouldReceive('ds')->andReturn($ds);
    $gateway = Mockery::mock(QuickBooksReadGateway::class, [$qb])->makePartial();
    $gateway->shouldReceive('realm')->andReturn('realm-one');
    $ds->shouldReceive('Query')->with('SELECT * FROM Invoice ORDERBY Id STARTPOSITION 101 MAXRESULTS 100')->once()->andReturn([]);
    $ds->shouldReceive('getLastError')->andReturn(false);
    expect($gateway->page('Invoice', 101))->toBe([]);
    $ds->shouldReceive('DownloadPDF')->with(Mockery::type(IPPInvoice::class), null, true)->once()->andReturn('');
    $invoice = importInvoice();
    expect(fn () => $gateway->pdf($invoice))->toThrow(RuntimeException::class);
    $errorDs = Mockery::mock(DataService::class);
    $errorDs->shouldReceive('Query')->once()->andReturn(null);
    $errorDs->shouldReceive('getLastError')->andReturn((object) ['error' => '429']);
    $errorQb = Mockery::mock(QuickBooksService::class);
    $errorQb->shouldReceive('ds')->andReturn($errorDs);
    $errorGateway = Mockery::mock(QuickBooksReadGateway::class, [$errorQb])->makePartial();
    $errorGateway->shouldReceive('realm')->andReturn('realm-one');
    expect(fn () => $errorGateway->page('Invoice', 1))->toThrow(RuntimeException::class);
});
it('rejects unsupported or grouped items and aggregates repeated physical requirements', function () {
    $p = invoicePayload();
    $p['Line'][] = $p['Line'][0];
    $invoice = app(InvoiceSyncService::class)->import($p, 'realm-one');
    $order = classifyInvoice($invoice);
    expect($order->workOrderAssets->first()->quantity)->toBe(4)->and($order->workOrderAssets()->count())->toBe(1);
    $p = invoicePayload(['Id' => '501']);
    $p['Line'][] = ['DetailType' => 'GroupLineDetail', 'Amount' => 0, 'GroupLineDetail' => []];
    $other = app(InvoiceSyncService::class)->import($p, 'realm-one');
    expect(fn () => classifyInvoice($other))->toThrow(ValidationException::class);
});
it('paginates physical Inventory imports without importing Service or resetting production weights', function () {
    $this->gateway->shouldReceive('page')->with('Item', 1)->andReturn([
        ['Id' => '100', 'Name' => 'Renamed', 'Type' => 'Inventory', 'Active' => false],
        ['Id' => '200', 'Name' => 'Labor', 'Type' => 'Service'],
        ['Id' => '300', 'Name' => 'Non-inventory goods', 'Type' => 'NonInventory'],
    ]);
    app(AssetSyncService::class)->sync();
    $asset = Asset::find(31);
    expect($asset->name)->toBe('Renamed')->and((int) $asset->weight)->toBe(2500)->and($asset->quickbooks_item_type)->toBe('Inventory')
        ->and(Asset::where('quickbooks_id', '200')->exists())->toBeFalse()->and(Asset::where('quickbooks_id', '300')->exists())->toBeFalse();
});
it('preserves operation data when synchronization fails and rolls back partial invoice updates', function () {
    $invoice = importInvoice();
    classifyInvoice($invoice);
    User::find(1)->assignRole('Admin');
    $this->gateway->shouldReceive('page')->with('Invoice', 1)->andReturn([invoicePayload(['Line' => []])]);
    expect(app(InvoiceSyncService::class)->sync(User::find(1)))->toBe(['synced' => 0, 'failed' => 1]);
    expect($invoice->fresh()->sync_status)->toBe('failed')->and($invoice->lines()->count())->toBe(3)->and($invoice->fresh()->operation_status)->toBe('created');
    DB::statement('CREATE TRIGGER fail_line BEFORE INSERT ON invoice_lines BEGIN SELECT RAISE(ABORT, "line failed"); END');
    expect(fn () => importInvoice(['TotalAmt' => 200]))->toThrow(QueryException::class);
    expect($invoice->fresh()->total)->toBe('110.00')->and($invoice->lines()->count())->toBe(3);
});

it('lists only owned Customer invoices and denies Worker financial access', function () {
    $own = importInvoice();
    $otherCustomer = Customer::create(['quickbooks_id' => '12', 'display_name' => 'Other']);
    $other = importInvoice(['Id' => '501', 'CustomerRef' => ['value' => '12']]);
    $buyer = User::create(['name' => 'Buyer', 'email' => 'own@example.test', 'password' => 'test']);
    $buyer->assignRole('Customer');
    $own->customer->update(['user_id' => $buyer->id]);
    $this->actingAs($buyer);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('customer'));
    Livewire\Livewire::test(App\Filament\Customer\Resources\InvoiceResource\Pages\ListInvoices::class)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$other]);
    $this->get(route('invoices.pdf', $other))->assertForbidden();
    $worker = User::create(['name' => 'Worker', 'email' => 'denyworker@example.test', 'password' => 'test']);
    $worker->assignRole('Worker');
    expect(fn () => app(InvoiceClassificationService::class)->classify($own->id, 'distribution', $worker))->toThrow(HttpException::class);
    $this->actingAs($worker)->get(route('invoices.pdf', $own))->assertForbidden();
});
it('shows Admin sync failures even if no Invoice was imported', function () {
    User::find(1)->assignRole('Admin');
    $event = InvoiceEvent::create(['event' => 'Invoice Sync Failed', 'metadata' => ['quickbooks_id' => '999', 'error' => 'Incomplete response']]);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    Livewire\Livewire::test(InvoiceSyncEvents::class)->assertCanSeeTableRecords([$event])->assertSee('Incomplete response');
});

it('rejects a company switch before reading another QuickBooks page', function () {
    (require database_path('migrations/2025_12_08_161529_create_quickbooks_tokens_table.php'))->up();
    DB::table('quickbooks_tokens')->insert(['realm_id' => 'realm-one', 'access_token' => 'unused', 'refresh_token' => 'unused']);
    $qb = Mockery::mock(QuickBooksService::class);
    $qb->shouldNotReceive('ds');
    $gateway = new QuickBooksReadGateway($qb);
    expect($gateway->realm())->toBe('realm-one');
    DB::table('quickbooks_tokens')->update(['realm_id' => 'realm-two']);
    expect(fn () => $gateway->page('Invoice', 101))->toThrow(RuntimeException::class);
});

it('normalizes supported reference representations', function ($reference) {
    expect(QuickBooksReadGateway::entityRef($reference))->toBe('100');
})->with([
    'lowercase' => [['value' => '100']], 'uppercase' => [['Value' => '100']],
    'string' => ['100'], 'integer' => [100],
    'sdk' => [new IPPReferenceType(['value' => '100'])],
]);

it('rejects malformed entity references without exposing their contents', function ($reference) {
    try {
        QuickBooksReadGateway::entityRef($reference, ['entity' => 'Item', 'line_index' => 0]);
        $this->fail('Expected invalid reference');
    } catch (InvoiceDataException $e) {
        expect($e->context)->toHaveKeys(['entity', 'line_index', 'reference_format']);
        expect($e->getMessage())->toBe('Invalid QuickBooks reference; previous data preserved.');
    }
})->with([null, '', 'private-name', '100 ', true, 1.5, [['value' => ['value' => '100']]], [[]], [(object) ['value' => '100']]]);

it('imports SDK object references and uppercase array references', function () {
    $p = invoicePayload();
    $p['CustomerRef'] = new IPPReferenceType(['value' => '10']);
    $p['Line'][0]['SalesItemLineDetail']['ItemRef'] = new IPPReferenceType(['value' => '100']);
    $p['Line'][1]['SalesItemLineDetail']['ItemRef'] = ['Value' => '200'];
    expect(app(InvoiceSyncService::class)->import($p, 'realm-one')->lines[0]->asset_id)->toBe(31);
});

it('preserves sales lines without item references for administrative review', function () {
    $p = invoicePayload();
    $p['Line'][0]['SalesItemLineDetail']['ItemRef'] = null;
    $invoice = app(InvoiceSyncService::class)->import($p, 'realm-one');
    expect($invoice->lines[0]->quickbooks_item_id)->toBeNull()->and($invoice->lines[0]->asset_id)->toBeNull()
        ->and($invoice->operation_status)->toBe('requires_review');
});

it('preserves discounts descriptions and groups without physical mappings', function () {
    $p = invoicePayload();
    $p['Line'][] = ['DetailType' => 'DiscountLineDetail', 'Amount' => 5, 'DiscountLineDetail' => ['DiscountPercent' => 5]];
    $p['Line'][] = ['DetailType' => 'DescriptionOnly', 'Description' => 'Memo'];
    $p['Line'][] = ['DetailType' => 'GroupLineDetail', 'Amount' => 100, 'GroupLineDetail' => ['GroupItemRef' => ['value' => '400'], 'Line' => [$p['Line'][0]]]];
    $invoice = app(InvoiceSyncService::class)->import($p, 'realm-one');
    expect($invoice->lines()->count())->toBe(6)->and($invoice->discount_total)->toEqual(5)
        ->and($invoice->lines[5]->asset_id)->toBeNull()->and($invoice->lines[5]->raw_payload)->toBe($p['Line'][5])
        ->and($invoice->operation_status)->toBe('requires_review');
});

it('skips representative invoice 1982 reference errors and continues with valid invoices', function () {
    // Representative regression fixture; the real production payload is unavailable.
    User::find(1)->assignRole('Admin');
    $p = invoicePayload(['Id' => '1982']);
    $p['Line'][0]['SalesItemLineDetail']['ItemRef'] = ['value' => 'private-invalid-reference'];
    $this->gateway->shouldReceive('page')->with('Invoice', 1)->andReturn([$p, invoicePayload()]);
    expect(app(InvoiceSyncService::class)->sync(User::find(1)))->toBe(['synced' => 1, 'failed' => 1]);
    $event = InvoiceEvent::where('event', 'Invoice Sync Skipped')->firstOrFail();
    expect($event->metadata)->toMatchArray(['quickbooks_id' => '1982', 'entity' => 'Item', 'line_index' => 0, 'line_type' => 'SalesItemLineDetail', 'reference_format' => 'invalid-value']);
    expect(json_encode($event->metadata))->not->toContain('private-invalid-reference');
    expect(InvoiceEvent::where('event', 'Invoice Sync Partial')->count())->toBe(1)
        ->and(InvoiceEvent::where('event', 'Invoice Sync Completed')->count())->toBe(0);
});

it('stops on global item read failures and does not reconcile absent invoices', function () {
    $invoice = importInvoice();
    User::find(1)->assignRole('Admin');
    $p = invoicePayload(['Id' => '1982']);
    $p['Line'][0]['SalesItemLineDetail']['ItemRef'] = ['value' => '999'];
    $this->gateway->shouldReceive('find')->with('Item', '999')->andThrow(new RuntimeException('Authentication or rate limit failure'));
    $this->gateway->shouldNotReceive('find')->with('Invoice', '500');
    $this->gateway->shouldReceive('page')->with('Invoice', 1)->andReturn([$p, invoicePayload(['Id' => '501'])]);
    expect(fn () => app(InvoiceSyncService::class)->sync(User::find(1)))->toThrow(RuntimeException::class);
    expect(Invoice::count())->toBe(1)->and($invoice->fresh()->sync_status)->toBe('synced')
        ->and(InvoiceEvent::where('event', 'Invoice Sync Stopped')->count())->toBe(1);
});

it('reuses items within a run and clears the cache between runs', function () {
    User::find(1)->assignRole('Admin');
    $gateway = Mockery::mock(QuickBooksReadGateway::class);
    $gateway->shouldReceive('realm')->andReturn('realm-one');
    $gateway->shouldReceive('page')->with('Invoice', 1)->twice()->andReturn(
        [invoicePayload(), invoicePayload(['Id' => '501'])],
        [invoicePayload(), invoicePayload(['Id' => '501']), invoicePayload(['Id' => '502'])],
    );
    $gateway->shouldReceive('find')->with('Item', '100')->twice()->andReturn(['Id' => '100', 'Type' => 'Inventory']);
    $gateway->shouldReceive('find')->with('Item', '200')->twice()->andReturn(['Id' => '200', 'Type' => 'Service']);
    $this->instance(QuickBooksReadGateway::class, $gateway);
    $service = app(InvoiceSyncService::class);
    $service->sync(User::find(1));
    $service->sync(User::find(1));
    expect(InvoiceEvent::where('event', 'Invoice Unchanged')->count())->toBe(2);
});

it('rolls back a newly imported customer when invoice writes fail', function () {
    $this->gateway->shouldReceive('find')->with('Customer', '11')->andReturn(['Id' => '11', 'DisplayName' => 'New buyer']);
    DB::statement('CREATE TRIGGER fail_line BEFORE INSERT ON invoice_lines BEGIN SELECT RAISE(ABORT, "line failed"); END');
    expect(fn () => importInvoice(['CustomerRef' => ['value' => '11']]))->toThrow(QueryException::class);
    expect(Customer::where('quickbooks_id', '11')->exists())->toBeFalse()->and(Invoice::count())->toBe(0);
});

it('stops the batch on database failure and keeps previous invoice lines', function () {
    $invoice = importInvoice();
    User::find(1)->assignRole('Admin');
    DB::statement('CREATE TRIGGER fail_line BEFORE INSERT ON invoice_lines BEGIN SELECT RAISE(ABORT, "line failed"); END');
    $this->gateway->shouldReceive('page')->with('Invoice', 1)->andReturn([invoicePayload(['TotalAmt' => 200]), invoicePayload(['Id' => '501'])]);
    expect(fn () => app(InvoiceSyncService::class)->sync(User::find(1)))->toThrow(QueryException::class);
    expect($invoice->fresh()->total)->toBe('110.00')->and($invoice->lines()->count())->toBe(3)
        ->and(Invoice::count())->toBe(1)->and(InvoiceEvent::where('event', 'Invoice Sync Stopped')->count())->toBe(1);
});

it('rejects malformed customer and nested group references', function () {
    expect(fn () => importInvoice(['CustomerRef' => ['value' => 'bad']]))->toThrow(InvoiceDataException::class);
    $p = invoicePayload();
    $p['Line'][] = ['DetailType' => 'GroupLineDetail', 'Amount' => 10, 'GroupLineDetail' => ['GroupItemRef' => ['value' => ['value' => '400']]]];
    expect(fn () => app(InvoiceSyncService::class)->import($p, 'realm-one'))->toThrow(InvoiceDataException::class);
    expect(Invoice::count())->toBe(0);
});

it('shows a warning notification for a partial Filament synchronization', function () {
    User::find(1)->assignRole('Admin');
    $this->gateway->shouldReceive('page')->with('Invoice', 1)->andReturn([invoicePayload(['Line' => []]), invoicePayload(['Id' => '501'])]);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    Livewire\Livewire::test(QuickBooksSync::class)->callAction('syncInvoices')
        ->assertNotified(Notification::make()->title('Invoice sync partially completed')
            ->body('1 synchronized; 1 skipped. Review Invoice Sync Events.')->warning());
});
