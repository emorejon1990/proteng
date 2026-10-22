<?php

use App\Filament\Admin\Pages\QuickBooksSync;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceClassificationService;
use App\Services\InvoiceRequirements;
use App\Services\InvoiceSelectiveSyncService;
use App\Services\InvoiceSelectiveVerification;
use App\Services\InvoiceSyncService;
use App\Services\QuickBooksReadGateway;
use App\Services\QuickBooksService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

require_once __DIR__.'/../Support/ProductionDatabase.php';
require_once __DIR__.'/../Support/InvoiceDatabase.php';
uses(TestCase::class);

function filteredInvoicePayload(string $id = '500', array $overrides = []): array
{
    return array_replace(['Id' => $id, 'DocNumber' => 'TEST-'.$id, 'CustomerRef' => '10',
        'TotalAmt' => 120, 'Balance' => 120, 'TxnDate' => '2026-10-22',
        'Line' => [
            ['Id' => '1', 'DetailType' => 'SalesItemLineDetail', 'Amount' => 100,
                'SalesItemLineDetail' => ['ItemRef' => '100', 'Qty' => 2]],
            ['Id' => '2', 'DetailType' => 'SalesItemLineDetail', 'Amount' => 20,
                'SalesItemLineDetail' => ['ItemRef' => '200', 'Qty' => 2.5]],
        ]], $overrides);
}

beforeEach(function () {
    setupTraceabilityDatabase();
    invoiceIntegrationUp();
    config(['permission.cache.store' => 'array']);
    app(PermissionRegistrar::class)->initializeCache();
    Role::create(['name' => 'Admin', 'guard_name' => 'web']);
    $this->actor = User::findOrFail(1)->assignRole('Admin');
    $this->actingAs($this->actor);
    Customer::create(['quickbooks_id' => '10', 'display_name' => 'Test']);
    DB::table('asset')->where('id', 31)->update(['quickbooks_id' => '100']);
    Http::preventStrayRequests();
    $qb = Mockery::mock(QuickBooksService::class);
    $qb->shouldNotReceive('ds');
    $this->instance(QuickBooksService::class, $qb);
    $this->items = [
        '100' => ['Id' => '100', 'Type' => 'Inventory', 'ParentRef' => ['value' => '900']],
        '200' => ['Id' => '200', 'Type' => 'NonInventory', 'ParentRef' => '901'],
        '900' => ['Id' => '900', 'Type' => 'Category', 'Name' => 'Product'],
        '901' => ['Id' => '901', 'Type' => 'Category', 'Name' => 'Other'],
    ];
    $this->gateway = Mockery::mock(QuickBooksReadGateway::class);
    $this->gateway->shouldReceive('realm')->andReturn('test-realm');
    $this->gateway->shouldReceive('find')->with('Item', Mockery::any())->andReturnUsing(function ($entity, $id) {
        if (! isset($this->items[$id])) {
            throw new RuntimeException('Unavailable mock Item');
        }

        return $this->items[$id];
    });
    $this->instance(QuickBooksReadGateway::class, $this->gateway);
});

afterEach(fn () => DB::disconnect('sqlite'));

it('filters pending partial paid void zero and inconsistent invoices', function ($overrides, $state, $result) {
    $filters = app(InvoiceSyncService::class)->filtersFor(filteredInvoicePayload(overrides: $overrides));
    expect($filters['payment_state'])->toBe($state)->and($filters['result'])->toBe($result);
})->with([
    [[], 'pending', 'candidate'], [['Balance' => 20], 'partial', 'candidate'],
    [['Balance' => 0], 'paid', 'excluded'], [['PrivateNote' => 'Voided'], 'void', 'excluded'],
    [['TotalAmt' => 0, 'Balance' => 0], 'unknown', 'review'],
    [['Balance' => -1], 'unknown', 'review'], [['Balance' => 121], 'unknown', 'review'],
]);

it('uses verified category ids and nested parents instead of Item type or FQN text', function () {
    $this->items['900'] = ['Id' => '900', 'Type' => 'Category', 'Name' => 'Nested', 'ParentRef' => '902'];
    $this->items['902'] = ['Id' => '902', 'Type' => 'Category', 'Name' => 'Product'];
    $invoice = app(InvoiceSyncService::class)->import(filteredInvoicePayload(), 'test-realm');
    $filters = $invoice->metadata['operational_filters'];
    expect($filters['product_category_id'])->toBe('902')->and($filters['product_items'])->toBe(1)
        ->and($filters['other_items'])->toBe(1)->and($invoice->lines[1]->asset_id)->toBeNull()
        ->and(app(InvoiceRequirements::class)->resolve($invoice))->toBe([31 => 2]);
});

it('reviews unavailable cyclic or mismatched categories rather than silently excluding items', function ($mode) {
    if ($mode === 'missing') {
        unset($this->items['900']);
    } elseif ($mode === 'cycle') {
        $this->items['900']['ParentRef'] = '900';
    } else {
        $this->items['900']['Id'] = '999';
    }
    $filters = app(InvoiceSyncService::class)->filtersFor(filteredInvoicePayload());
    expect($filters['unknown_items'])->toBe(1)->and($filters['result'])->toBe('review');
})->with(['missing', 'cycle', 'mismatch']);

it('skips new excluded invoices and caches each unique Item across a general synchronization', function () {
    $this->gateway->shouldReceive('page')->with('Invoice', 1)->once()->andReturn([
        filteredInvoicePayload('500'), filteredInvoicePayload('501', ['Balance' => 50]),
        filteredInvoicePayload('502', ['Balance' => 0]),
    ]);
    $summary = app(InvoiceSyncService::class)->sync($this->actor);
    expect($summary)->toMatchArray(['synced' => 2, 'excluded' => 1, 'review' => 0, 'failed' => 0])
        ->and(Invoice::where('quickbooks_id', '502')->exists())->toBeFalse()
        ->and(DB::table('work_orders')->count())->toBe(0);
    foreach (['100', '200', '900', '901'] as $id) {
        $this->gateway->shouldHaveReceived('find')->with('Item', $id)->once();
    }
});

it('keeps included physical quantity errors and excludes nonProduct missing Assets', function () {
    $p = filteredInvoicePayload();
    $p['Line'][0]['SalesItemLineDetail']['Qty'] = 2.5;
    $invoice = app(InvoiceSyncService::class)->import($p, 'test-realm');
    expect($invoice->operation_status)->toBe('requires_review')
        ->and($invoice->events()->latest('id')->first()->metadata['operational_diagnostics'][0]['code'])->toBe('invalid_physical_quantity');
});

it('keeps selective exclusions successful and reads current categories without changing stored invoices', function () {
    $p = filteredInvoicePayload('1982');
    $invoice = app(InvoiceSyncService::class)->import($p, 'test-realm');
    $verification = app(InvoiceSelectiveVerification::class);
    $before = $verification->snapshot('1982');
    // A fresh capture must observe this category change despite an identical Invoice payload.
    $this->items['100']['ParentRef'] = '901';
    foreach (InvoiceSelectiveSyncService::IDS as $id) {
        $this->gateway->shouldReceive('find')->with('Invoice', $id)->andReturn(filteredInvoicePayload($id));
    }
    $result = app(InvoiceSelectiveSyncService::class)->dryRun($this->actor);
    expect($result['report']['passed'])->toBeTrue()->and($result['report']['invoices']['1982']['selection_result'])->toBe('excluded')
        ->and($result['report']['invoices']['1982']['filters']['product_items'])->toBe(0)
        ->and($result['report']['invoices']['11241']['change'])->toBe('not_imported')
        ->and($verification->snapshot('1982'))->toBe($before)->and(Invoice::count())->toBe(1);
});

it('reports current category changes against linked snapshots without modifying real source operations', function () {
    $invoice = app(InvoiceSyncService::class)->import(filteredInvoicePayload('1982'), 'test-realm');
    app(InvoiceClassificationService::class)->classify($invoice->id, 'distribution', $this->actor);
    $verification = app(InvoiceSelectiveVerification::class);
    $before = $verification->snapshot('1982');
    $this->items['100']['ParentRef'] = '901';
    foreach (InvoiceSelectiveSyncService::IDS as $id) {
        $this->gateway->shouldReceive('find')->with('Invoice', $id)->andReturn(filteredInvoicePayload($id));
    }
    $result = app(InvoiceSelectiveSyncService::class)->dryRun($this->actor);
    expect($result['report']['invoices']['1982']['selection_result'])->toBe('review')
        ->and($result['report']['invoices']['1982']['checks']['current_operation_consistent'])->toBeFalse()
        ->and($verification->snapshot('1982'))->toBe($before);
});

it('renders the existing Dry Run filters and downloads its JSON without persistent imports', function () {
    foreach (InvoiceSelectiveSyncService::IDS as $id) {
        $this->gateway->shouldReceive('find')->with('Invoice', $id)
            ->andReturn(filteredInvoicePayload($id, ['Balance' => $id === '1982' ? 0 : 50]));
    }
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    Livewire\Livewire::test(QuickBooksSync::class)
        ->callAction('testThreeInvoices')->assertSee('Estado de pago')->assertSee('Pagada')->assertSee('Parcial')
        ->assertSee('Items Product')->assertSee('Excluida')->assertSee('Candidata')
        ->callAction('downloadSelectiveReport')->assertFileDownloaded('quickbooks_selective_test.json');
    expect(Invoice::count())->toBe(0)->and(DB::table('work_orders')->count())->toBe(0);
    $this->gateway->shouldHaveReceived('find')->with('Item', '900')->once();
});
