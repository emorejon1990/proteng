<?php

use App\Filament\Admin\Pages\QuickBooksSync;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\User;
use App\Services\AssetSyncService;
use App\Services\CustomerSyncService;
use App\Services\InvoiceClassificationService;
use App\Services\InvoiceSelectiveSandbox;
use App\Services\InvoiceSelectiveSyncService;
use App\Services\InvoiceSyncService;
use App\Services\QuickBooksReadGateway;
use App\Services\QuickBooksService;
use App\Services\QuickBooksSyncLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

require_once __DIR__.'/../Support/ProductionDatabase.php';
require_once __DIR__.'/../Support/InvoiceDatabase.php';
require_once __DIR__.'/../Support/QuickBooksCapturedFixtures.php';
uses(TestCase::class);

beforeEach(function () {
    setupTraceabilityDatabase();
    invoiceIntegrationUp();
    config(['permission.cache.store' => 'array', 'cache.default' => 'array']);
    app(PermissionRegistrar::class)->initializeCache();
    foreach (['Admin', 'Manager', 'Customer'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web']);
    }
    $this->actor = User::findOrFail(1)->assignRole('Admin');
    $this->actingAs($this->actor);
    Customer::create(['quickbooks_id' => '10', 'display_name' => 'Test buyer']);
    Http::preventStrayRequests();
    $qb = Mockery::mock(QuickBooksService::class);
    $qb->shouldNotReceive('ds');
    $this->instance(QuickBooksService::class, $qb);
    $this->payloads = [];
    $this->itemIds = [];
    foreach (InvoiceSelectiveSyncService::IDS as $id) {
        $this->payloads[$id] = capturedInvoice($id, 'sdk');
        foreach ($this->payloads[$id]['Line'] as $line) {
            $item = $line['SalesItemLineDetail']['ItemRef'] ?? null;
            if ($item !== null && $item !== 'SHIPPING_ITEM_ID') {
                $this->itemIds[$item] = true;
            }
        }
    }
    $this->gateway = Mockery::mock(QuickBooksReadGateway::class);
    $this->gateway->shouldReceive('realm')->andReturn('fixture-realm');
    $this->gateway->shouldNotReceive('page');
    $this->gateway->shouldNotReceive('find')->with('Item', 'SHIPPING_ITEM_ID');
    foreach (InvoiceSelectiveSyncService::IDS as $id) {
        $this->gateway->shouldReceive('find')->with('Invoice', $id)->andReturnUsing(fn () => $this->payloads[$id]);
    }
    foreach (array_keys($this->itemIds) as $id) {
        $this->gateway->shouldReceive('find')->with('Item', (string) $id)->andReturn(['Id' => (string) $id, 'Type' => $id == '1' ? 'Service' : 'Inventory']);
        if ($id != '1') {
            DB::table('asset')->insert(['name' => 'Fixture '.$id, 'weight' => 1, 'weight_tolerance' => 1, 'quickbooks_id' => (string) $id]);
        }
    }
    $this->instance(QuickBooksReadGateway::class, $this->gateway);
});

afterEach(function () {
    DB::disconnect('sqlite');
});

function selectiveDatabaseRows(): array
{
    $rows = [];
    foreach (InvoiceSelectiveSandbox::TABLES as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    return $rows;
}

it('runs the actual isolated subprocess for only three invoices without changing existing data or events', function () {
    $source = DB::connection()->getPdo();
    $before = selectiveDatabaseRows();
    $temporary = glob(sys_get_temp_dir().'/qb-selective-*');
    $result = app(InvoiceSelectiveSyncService::class)->dryRun($this->actor);
    expect($result['report']['passed'])->toBeTrue()->and($result['ticket'])->toBeString()
        ->and(array_map('strval', array_keys($result['report']['invoices'])))->toBe(InvoiceSelectiveSyncService::IDS)
        ->and(selectiveDatabaseRows())->toBe($before)->and(DB::connection()->getPdo())->toBe($source)
        ->and(glob(sys_get_temp_dir().'/qb-selective-*'))->toBe($temporary);
    foreach ($result['report']['invoices'] as $row) {
        expect($row['consulted'])->toBeTrue()->and($row['passed'])->toBeTrue()->and($row['change'])->toBe('new');
    }
    Http::assertNothingSent();
});

it('requires an approved dry run and a second explicit confirmation for Apply', function () {
    $service = app(InvoiceSelectiveSyncService::class);
    expect(fn () => $service->apply($this->actor, 'forged', true))->toThrow(HttpException::class);
    $dry = $service->dryRun($this->actor);
    expect($dry['report']['passed'])->toBeTrue();
    expect(fn () => $service->apply($this->actor, $dry['ticket'], false))->toThrow(HttpException::class);
    expect(Invoice::count())->toBe(0)->and(InvoiceEvent::count())->toBe(0);
    $apply = $service->apply($this->actor, $dry['ticket'], true);
    expect($apply['passed'])->toBeTrue()->and(Invoice::count())->toBe(3)->and(InvoiceEvent::count())->toBe(3);
    expect(fn () => $service->apply($this->actor, $dry['ticket'], true))->toThrow(HttpException::class);
});

it('preserves linked operations snapshots quantities and unchanged line IDs', function () {
    $importer = app(InvoiceSyncService::class);
    foreach ($this->payloads as $payload) {
        $invoice = $importer->import($payload, 'fixture-realm');
        app(InvoiceClassificationService::class)->classify($invoice->id, 'distribution', $this->actor);
    }
    $before = selectiveDatabaseRows();
    $service = app(InvoiceSelectiveSyncService::class);
    $dry = $service->dryRun($this->actor);
    expect($dry['report']['passed'])->toBeTrue()->and(selectiveDatabaseRows())->toBe($before);
    $report = $service->apply($this->actor, $dry['ticket'], true);
    expect($report['passed'])->toBeTrue();
    $after = selectiveDatabaseRows();
    foreach (['invoices', 'invoice_lines', 'work_orders', 'wo_ass', 'installations'] as $table) {
        expect($after[$table])->toBe($before[$table]);
    }
    foreach ($report['invoices'] as $result) {
        expect($result['change'])->toBe('unchanged')->and($result['checks']['unchanged_line_ids'])->toBeTrue();
    }
});

it('blocks Apply if QuickBooks responses change and identifies the changed field', function () {
    $service = app(InvoiceSelectiveSyncService::class);
    $dry = $service->dryRun($this->actor);
    $this->payloads['1982']['TotalAmt'] = '999.00';
    $report = $service->apply($this->actor, $dry['ticket'], true);
    expect($report['passed'])->toBeFalse()->and(implode(' ', $report['errors']))->toContain('responses.Invoice.1982.TotalAmt')
        ->and(Invoice::count())->toBe(0)->and(InvoiceEvent::count())->toBe(0);
});

it('blocks Apply if local mappings change after Dry Run', function () {
    $service = app(InvoiceSelectiveSyncService::class);
    $dry = $service->dryRun($this->actor);
    DB::table('asset')->whereNotNull('quickbooks_id')->update(['weight' => 9]);
    $before = selectiveDatabaseRows();
    $report = $service->apply($this->actor, $dry['ticket'], true);
    expect($report['passed'])->toBeFalse()->and(implode(' ', $report['errors']))->toContain('local.asset')
        ->and(selectiveDatabaseRows())->toBe($before);
});

it('shares the lock with invoice customer product and selective synchronizations', function () {
    $lock = Cache::lock(QuickBooksSyncLock::KEY, 3600);
    $lock->get();
    try {
        expect(fn () => app(InvoiceSelectiveSyncService::class)->dryRun($this->actor))->toThrow(RuntimeException::class);
        expect(fn () => app(InvoiceSyncService::class)->sync($this->actor))->toThrow(RuntimeException::class);
        expect(fn () => app(CustomerSyncService::class)->sync())->toThrow(RuntimeException::class);
        expect(fn () => app(AssetSyncService::class)->sync())->toThrow(RuntimeException::class);
        expect(Invoice::count())->toBe(0);
    } finally {
        $lock->release();
    }
});

it('does not offer Apply after a failed Dry Run and exports safe individual errors', function () {
    $this->payloads['11241']['Line'][1]['SubTotalLineDetail'] = 'unexpected';
    $before = selectiveDatabaseRows();
    $dry = app(InvoiceSelectiveSyncService::class)->dryRun($this->actor);
    expect($dry['ticket'])->toBeNull()->and($dry['report']['passed'])->toBeFalse()
        ->and($dry['report']['invoices']['11241']['passed'])->toBeFalse()
        ->and($dry['report']['invoices']['1982']['passed'])->toBeTrue()->and(selectiveDatabaseRows())->toBe($before);
    expect(json_encode($dry['report']))->not->toContain('access_token', 'refresh_token', 'client_secret', 'Test buyer');
});

it('rolls back the failing Apply and stops before processing subsequent invoices', function () {
    $service = app(InvoiceSelectiveSyncService::class);
    $dry = $service->dryRun($this->actor);
    DB::statement('CREATE TRIGGER fail_selective BEFORE INSERT ON invoice_lines BEGIN SELECT RAISE(ABORT, "write failure"); END');
    $report = $service->apply($this->actor, $dry['ticket'], true);
    expect($report['passed'])->toBeFalse()->and($report['invoices']['1982']['final_state'])->toBe('rolled_back')
        ->and($report['invoices']['1982']['checks']['failed_import_preserved_data'])->toBeTrue()
        ->and($report['invoices']['11241']['final_state'])->toBe('not_processed')
        ->and(Invoice::count())->toBe(0)->and(InvoiceEvent::count())->toBe(0);
});

it('rolls back unexpected operation side effects found by mandatory checks', function () {
    foreach ($this->payloads as $payload) {
        $invoice = app(InvoiceSyncService::class)->import($payload, 'fixture-realm');
        app(InvoiceClassificationService::class)->classify($invoice->id, 'distribution', $this->actor);
    }
    $this->payloads['1982']['Balance'] = '1.00';
    $service = app(InvoiceSelectiveSyncService::class);
    $dry = $service->dryRun($this->actor);
    expect($dry['report']['passed'])->toBeTrue();
    $before = selectiveDatabaseRows();
    Invoice::updated(function (Invoice $invoice) {
        DB::table('wo_ass')->where('work_order_id', $invoice->work_order_id)->increment('quantity');
    });
    try {
        $report = $service->apply($this->actor, $dry['ticket'], true);
        expect($report['passed'])->toBeFalse()->and($report['invoices']['1982']['checks']['preserved_quantities'])->toBeFalse()
            ->and($report['invoices']['1982']['final_state'])->toBe('rolled_back')->and(selectiveDatabaseRows())->toBe($before);
    } finally {
        Invoice::flushEventListeners();
    }
});

it('denies unauthorized users before any selective read', function ($roles) {
    $this->actor->syncRoles($roles);
    expect(fn () => app(InvoiceSelectiveSyncService::class)->dryRun($this->actor))->toThrow(HttpException::class);
    expect(fn () => app(InvoiceSelectiveSyncService::class)->apply($this->actor, 'forged', true))->toThrow(HttpException::class);
    expect(Invoice::count())->toBe(0);
})->with([[['Manager']], [['Customer']], [['Admin', 'Customer']]]);

it('renders a confirmed default Dry Run action and hides Apply before approval', function () {
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    Livewire::test(QuickBooksSync::class)->assertActionVisible('testThreeInvoices')->assertActionHidden('applyThreeInvoices')
        ->mountAction('testThreeInvoices')->assertActionMounted('testThreeInvoices')->assertSee('1982, 11241, 11990');
    expect(Invoice::count())->toBe(0)->and(InvoiceEvent::count())->toBe(0);
});

it('does not rewrite unchanged lines when JSON object keys are reordered', function () {
    $payload = $this->payloads['11241'];
    $importer = app(InvoiceSyncService::class);
    $invoice = $importer->import($payload, 'fixture-realm');
    $lineIds = $invoice->lines()->pluck('id')->all();
    $payload = array_reverse($payload, true);
    foreach ($payload['Line'] as &$line) {
        $line = array_reverse($line, true);
    }
    unset($line);
    $again = $importer->import($payload, 'fixture-realm');
    expect($again->lines()->pluck('id')->all())->toBe($lineIds)->and(InvoiceEvent::where('event', 'Invoice Unchanged')->count())->toBe(1);
});

it('rejects expired approval and approval belonging to another administrator', function () {
    $service = app(InvoiceSelectiveSyncService::class);
    $dry = $service->dryRun($this->actor);
    $other = User::create(['name' => 'Other admin', 'email' => 'other-selective@example.test', 'password' => 'unused']);
    $other->assignRole('Admin');
    expect(fn () => $service->apply($other, $dry['ticket'], true))->toThrow(HttpException::class);
    $this->travel(16)->minutes();
    expect(fn () => $service->apply($this->actor, $dry['ticket'], true))->toThrow(HttpException::class);
    $this->travelBack();
    expect(Invoice::count())->toBe(0)->and(InvoiceEvent::count())->toBe(0);
});

it('checks lock ownership again before writing after API reads', function () {
    $importer = app(InvoiceSyncService::class);
    app(QuickBooksSyncLock::class)->run(function () use ($importer) {
        Cache::lock(QuickBooksSyncLock::KEY)->forceRelease();
        expect(fn () => $importer->import($this->payloads['1982'], 'fixture-realm'))->toThrow(RuntimeException::class);
    });
    expect(Invoice::count())->toBe(0)->and(InvoiceEvent::count())->toBe(0);
});

it('reports global API errors without querying any subsequent invoices', function () {
    $gateway = Mockery::mock(QuickBooksReadGateway::class);
    $gateway->shouldReceive('realm')->andReturn('fixture-realm');
    $gateway->shouldReceive('find')->with('Invoice', '1982')->once()->andThrow(new RuntimeException('Bearer PRIVATE-TOKEN'));
    $gateway->shouldNotReceive('find')->with('Invoice', '11241');
    $gateway->shouldNotReceive('find')->with('Invoice', '11990');
    $gateway->shouldNotReceive('page');
    $this->instance(QuickBooksReadGateway::class, $gateway);
    $result = app(InvoiceSelectiveSyncService::class)->dryRun($this->actor);
    expect($result['ticket'])->toBeNull()->and($result['report']['passed'])->toBeFalse()
        ->and($result['report']['invoices']['1982']['errors'])->not->toBeEmpty()
        ->and($result['report']['invoices']['11241']['consulted'])->toBeFalse()
        ->and(json_encode($result['report']))->not->toContain('PRIVATE-TOKEN');
});

it('uses the two UI confirmations and supplies a private downloadable report', function () {
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    $component = Livewire::test(QuickBooksSync::class)->callAction('testThreeInvoices')
        ->assertSet('selectiveReport.passed', true)->assertActionVisible('applyThreeInvoices')
        ->callAction('downloadSelectiveReport')->assertFileDownloaded('quickbooks_selective_test.json');
    expect(Invoice::count())->toBe(0);
    $component->callAction('applyThreeInvoices', ['confirmApply' => false])->assertHasActionErrors(['confirmApply']);
    expect(Invoice::count())->toBe(0);
    $component->callAction('applyThreeInvoices', ['confirmApply' => true])->assertHasNoActionErrors()
        ->assertSet('selectiveReport.passed', true)->assertSet('selectiveReport.mode', 'apply')->assertActionHidden('applyThreeInvoices');
    expect(Invoice::count())->toBe(3);
});
