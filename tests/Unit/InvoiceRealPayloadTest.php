<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\User;
use App\Services\InvoiceClassificationService;
use App\Services\InvoiceDataException;
use App\Services\InvoiceSyncService;
use App\Services\QuickBooksReadGateway;
use App\Services\QuickBooksService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

require_once __DIR__.'/../Support/ProductionDatabase.php';
require_once __DIR__.'/../Support/InvoiceDatabase.php';
require_once __DIR__.'/../Support/QuickBooksCapturedFixtures.php';
uses(TestCase::class);

beforeEach(function () {
    setupTraceabilityDatabase();
    invoiceIntegrationUp();
    config(['permission.cache.store' => 'array']);
    app(PermissionRegistrar::class)->initializeCache();
    Role::create(['name' => 'Admin', 'guard_name' => 'web']);
    $this->actingAs(User::findOrFail(1)->assignRole('Admin'));
    Customer::create(['quickbooks_id' => '10', 'display_name' => 'Sanitized buyer']);
    Http::preventStrayRequests();
    $qb = Mockery::mock(QuickBooksService::class);
    $qb->shouldNotReceive('ds');
    $this->instance(QuickBooksService::class, $qb);
    $gateway = Mockery::mock(QuickBooksReadGateway::class);
    $gateway->shouldReceive('realm')->andReturn('fixture-realm');
    $gateway->shouldNotReceive('page');
    $gateway->shouldNotReceive('find')->with('Item', 'SHIPPING_ITEM_ID');
    $this->itemIds = [];
    foreach (['1982', '11241', '11990'] as $id) {
        foreach (capturedInvoice($id, 'raw')['Line'] as $line) {
            $item = $line['SalesItemLineDetail']['ItemRef']['value'] ?? null;
            if ($item && $item !== 'SHIPPING_ITEM_ID') {
                $this->itemIds[$item] = true;
            }
        }
    }
    // The ZIP contains invoices, not Item responses. Types/mappings below are isolated test assumptions.
    foreach (array_keys($this->itemIds) as $item) {
        $gateway->shouldReceive('find')->with('Item', (string) $item)->andReturn(['Id' => (string) $item, 'Type' => $item == '1' ? 'Service' : 'Inventory']);
        if ($item != '1') {
            DB::table('asset')->insert(['name' => 'Fixture Item '.$item, 'weight' => 1, 'weight_tolerance' => 1, 'quickbooks_id' => (string) $item]);
        }
    }
    $this->instance(QuickBooksReadGateway::class, $gateway);
    $this->gateway = $gateway;
});

afterEach(function () {
    DB::disconnect('sqlite');
});

dataset('captured invoices', [
    ['1982', 'raw'], ['1982', 'sdk'], ['11241', 'raw'], ['11241', 'sdk'], ['11990', 'raw'], ['11990', 'sdk'],
]);

it('imports every captured line and preserves accounting amounts without duplicates', function ($id, $format) {
    $p = capturedInvoice($id, $format);
    $invoice = app(InvoiceSyncService::class)->import($p, 'fixture-realm');
    expect($invoice->total)->toBe(number_format((float) $p['TotalAmt'], 2, '.', ''))
        ->and($invoice->balance)->toBe(number_format((float) $p['Balance'], 2, '.', ''))
        ->and($invoice->lines()->count())->toBe(count($p['Line']));
    foreach ($invoice->lines as $index => $line) {
        expect($line->amount)->toBe(number_format((float) $p['Line'][$index]['Amount'], 2, '.', ''))
            ->and($line->detail_type)->toBe($p['Line'][$index]['DetailType']);
        if ($line->detail_type !== 'SalesItemLineDetail' || $line->quickbooks_item_id === 'SHIPPING_ITEM_ID') {
            expect($line->asset_id)->toBeNull();
        }
    }
    expect((float) $invoice->discount_total)->toBe((float) collect($p['Line'])->where('DetailType', 'DiscountLineDetail')->sum('Amount'));
    expect((float) $invoice->tax_total)->toBe((float) $p['TxnTaxDetail']['TotalTax']);
    $sales = collect($p['Line'])->where('DetailType', 'SalesItemLineDetail')->sum('Amount');
    expect(round($sales - (float) $invoice->discount_total + (float) $invoice->tax_total, 2))->toBe((float) $invoice->total);
    if ($id === '1982') {
        $shipping = $invoice->lines->firstWhere('quickbooks_item_id', 'SHIPPING_ITEM_ID');
        expect($shipping->amount)->toBe('800.00')->and($shipping->item_type)->toBe('Shipping');
    }
    $before = $invoice->getRawOriginal();
    $lineIds = $invoice->lines()->pluck('id')->all();
    $again = app(InvoiceSyncService::class)->import($p, 'fixture-realm');
    expect($again->getRawOriginal())->toBe($before)->and($again->lines()->pluck('id')->all())->toBe($lineIds)
        ->and(Invoice::count())->toBe(1)->and(InvoiceEvent::where('event', 'Invoice Unchanged')->count())->toBe(1);
    Http::assertNothingSent();
})->with('captured invoices');

it('preserves a linked operation on accounting changes and flags physical conflicts', function ($id, $format) {
    $service = app(InvoiceSyncService::class);
    $p = capturedInvoice($id, $format);
    $invoice = $service->import($p, 'fixture-realm');
    $order = app(InvoiceClassificationService::class)->classify($invoice->id, 'distribution', User::findOrFail(1));
    $protectedOrder = $order->fresh()->getRawOriginal();
    $protectedQuantities = $order->workOrderAssets()->pluck('quantity', 'asset_id')->all();
    $snapshot = $invoice->fresh()->operation_snapshot;
    $lineIds = $invoice->lines()->pluck('id')->all();
    $unchanged = $service->import($p, 'fixture-realm');
    expect($unchanged->work_order_id)->toBe($order->id)->and($unchanged->operation_status)->toBe('created')
        ->and($unchanged->lines()->pluck('id')->all())->toBe($lineIds);
    $p['Balance'] = 0;
    $p['TotalAmt'] = (float) $p['TotalAmt'] + 5;
    if ($id === '1982') {
        $p['Line'][17]['Amount'] = 805;
    }
    $updated = $service->import($p, 'fixture-realm');
    expect($updated->work_order_id)->toBe($order->id)->and($updated->operation_status)->toBe('created')
        ->and($updated->operation_snapshot)->toBe($snapshot)->and(Invoice::count())->toBe(1);
    $p['Line'][0]['SalesItemLineDetail']['Qty'] = (float) $p['Line'][0]['SalesItemLineDetail']['Qty'] + 1;
    $conflict = $service->import($p, 'fixture-realm');
    expect($conflict->operation_status)->toBe('sync_conflict')->and($conflict->operation_type)->toBe('distribution')
        ->and($conflict->operation_snapshot)->toBe($snapshot)->and($order->fresh()->getRawOriginal())->toBe($protectedOrder)
        ->and($order->workOrderAssets()->pluck('quantity', 'asset_id')->all())->toBe($protectedQuantities);
})->with('captured invoices');

it('rolls back captured invoice updates on SQL failure', function ($id, $format) {
    $p = capturedInvoice($id, $format);
    $service = app(InvoiceSyncService::class);
    $invoice = $service->import($p, 'fixture-realm');
    $before = $invoice->getRawOriginal();
    $lines = $invoice->lines()->get()->map->getRawOriginal()->all();
    DB::statement('CREATE TRIGGER fail_real_line BEFORE INSERT ON invoice_lines BEGIN SELECT RAISE(ABORT, "fixture write failure"); END');
    $p['TotalAmt'] = (float) $p['TotalAmt'] + 1;
    expect(fn () => $service->import($p, 'fixture-realm'))->toThrow(QueryException::class);
    expect($invoice->fresh()->getRawOriginal())->toBe($before)
        ->and($invoice->lines()->get()->map->getRawOriginal()->all())->toBe($lines);
})->with('captured invoices');

it('rejects unknown structures without changing an existing captured invoice', function () {
    $p = capturedInvoice('11241', 'sdk');
    $invoice = app(InvoiceSyncService::class)->import($p, 'fixture-realm');
    $before = $invoice->getRawOriginal();
    $p['Line'][1]['SubTotalLineDetail'] = 'unexpected';
    expect(fn () => app(InvoiceSyncService::class)->import($p, 'fixture-realm'))->toThrow(InvoiceDataException::class);
    $p['Line'][1]['DetailType'] = 'UnknownLineDetail';
    expect(fn () => app(InvoiceSyncService::class)->import($p, 'fixture-realm'))->toThrow(InvoiceDataException::class);
    expect($invoice->fresh()->getRawOriginal())->toBe($before);
});

it('keeps ordinary entity references strict and never looks up the shipping sentinel', function () {
    expect(fn () => QuickBooksReadGateway::entityRef('SHIPPING_ITEM_ID'))->toThrow(InvoiceDataException::class);
    $p = capturedInvoice('1982', 'sdk');
    $p['Line'][17]['SalesItemLineDetail']['ItemRef'] = 'OTHER_SPECIAL_ID';
    expect(fn () => app(InvoiceSyncService::class)->import($p, 'fixture-realm'))->toThrow(InvoiceDataException::class);
    expect(Invoice::count())->toBe(0);
});
