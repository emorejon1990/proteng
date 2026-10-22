<?php

use App\Filament\Admin\Pages\QuickBooksSync;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceSelectiveSandbox;
use App\Services\InvoiceSelectiveSyncService;
use App\Services\QuickBooksReadGateway;
use App\Services\QuickBooksService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
    Role::create(['name' => 'Admin', 'guard_name' => 'web']);
    $this->actor = User::findOrFail(1)->assignRole('Admin');
    $this->actingAs($this->actor);
    Customer::create(['quickbooks_id' => '10', 'display_name' => 'Test']);
    DB::table('asset')->where('id', 31)->update(['quickbooks_id' => '100']);
    Http::preventStrayRequests();
    $qb = Mockery::mock(QuickBooksService::class);
    $qb->shouldNotReceive('ds');
    $this->instance(QuickBooksService::class, $qb);
    $this->gateway = Mockery::mock(QuickBooksReadGateway::class);
    $this->gateway->shouldReceive('realm')->andReturn('fixture-realm');
    $this->gateway->shouldReceive('find')->with('Item', '100')->andReturn(['Id' => '100', 'Type' => 'Inventory', 'ParentRef' => '900']);
    $this->gateway->shouldReceive('find')->with('Item', '900')->andReturn(['Id' => '900', 'Type' => 'Category', 'Name' => 'Product']);
    $this->instance(QuickBooksReadGateway::class, $this->gateway);
});

afterEach(fn () => DB::disconnect('sqlite'));

function randomDryRunPayload(string $id): array
{
    return ['Id' => $id, 'CustomerRef' => '10', 'TotalAmt' => 10, 'Balance' => 0, 'TxnDate' => '2026-10-22',
        'Line' => [['Id' => '1', 'Amount' => 10, 'DetailType' => 'SalesItemLineDetail',
            'SalesItemLineDetail' => ['ItemRef' => '100', 'Qty' => 1]]]];
}

function finishRandomDryRun(InvoiceSelectiveSyncService $service, User $actor, string $run): array
{
    for ($tick = 0; $tick < 200; $tick++) {
        $report = $service->advanceDryRun($actor, $run);
        if ($report['status'] !== 'running') {
            return $report;
        }
    }
    throw new RuntimeException('Dry Run did not complete within test budget.');
}

it('draws exactly 50 distinct positions without listing the invoice catalog', function () {
    $this->gateway->shouldReceive('invoiceCount')->once()->andReturn(10000);
    $this->gateway->shouldNotReceive('page');
    $run = app(InvoiceSelectiveSyncService::class)->dryRun($this->actor);
    expect($run['ticket'])->toBeNull()->and($run['report']['selected_positions'])->toHaveCount(50)
        ->and(count(array_unique($run['report']['selected_positions'])))->toBe(50)
        ->and(min($run['report']['selected_positions']))->toBeGreaterThanOrEqual(1)
        ->and(max($run['report']['selected_positions']))->toBeLessThanOrEqual(10000);
});

it('processes a stable 50 invoice sample caching Items and keeping exclusions out of technical errors', function () {
    $this->gateway->shouldReceive('invoiceCount')->once()->andReturn(80);
    $this->gateway->shouldReceive('page')->with('Invoice', Mockery::type('int'), 1)->times(50)
        ->andReturnUsing(fn ($entity, $position) => [randomDryRunPayload((string) (1000 + $position))]);
    $sandbox = Mockery::mock(InvoiceSelectiveSandbox::class);
    $sandbox->shouldReceive('run')->times(50)->andReturnUsing(function ($input) {
        $id = $input['ids'][0];

        return [$id => ['consulted' => true, 'passed' => true, 'final_state' => 'excluded', 'selection_result' => 'excluded',
            'filters' => $input['filters'][$id], 'errors' => [], 'seconds' => 0]];
    });
    $this->instance(InvoiceSelectiveSandbox::class, $sandbox);
    $service = app(InvoiceSelectiveSyncService::class);
    $run = $service->dryRun($this->actor);
    $report = finishRandomDryRun($service, $this->actor, $run['run_id']);
    expect($report['selected_positions'])->toBe($run['report']['selected_positions'])
        ->and($report['selected_ids'])->toHaveCount(50)->and(count(array_unique($report['selected_ids'])))->toBe(50)
        ->and($report['summary'])->toMatchArray(['selected' => 50, 'consulted' => 50, 'processed' => 50,
            'paid' => 50, 'excluded_payment' => 50, 'technical_errors' => 0, 'with_product' => 50])
        ->and($report['passed'])->toBeTrue()->and(Invoice::count())->toBe(0)->and(DB::table('work_orders')->count())->toBe(0);
    $this->gateway->shouldHaveReceived('find')->with('Item', '100')->once();
    $this->gateway->shouldHaveReceived('find')->with('Item', '900')->once();
});

it('runs the real isolated subprocess for a dynamic scope and retains other results on an invoice read failure', function () {
    $this->gateway->shouldReceive('invoiceCount')->once()->andReturn(2);
    $this->gateway->shouldReceive('page')->with('Invoice', 1, 1)->once()->andReturn([randomDryRunPayload('700')]);
    $this->gateway->shouldReceive('page')->with('Invoice', 2, 1)->once()->andThrow(new RuntimeException('unavailable'));
    $service = app(InvoiceSelectiveSyncService::class);
    $run = $service->dryRun($this->actor);
    $report = finishRandomDryRun($service, $this->actor, $run['run_id']);
    expect($report['notice'])->not->toBeNull()->and($report['invoices'][700]['passed'])->toBeTrue()
        ->and($report['summary'])->toMatchArray(['consulted' => 1, 'processed' => 2, 'technical_errors' => 1])
        ->and(Invoice::count())->toBe(0)->and(DB::table('invoice_events')->count())->toBe(0);
    Http::assertNothingSent();
});

it('stops on shifted listing duplicates without silently drawing replacements', function () {
    $this->gateway->shouldReceive('invoiceCount')->once()->andReturn(2);
    $this->gateway->shouldReceive('page')->with('Invoice', Mockery::type('int'), 1)->twice()->andReturn([randomDryRunPayload('700')]);
    $service = app(InvoiceSelectiveSyncService::class);
    $run = $service->dryRun($this->actor);
    $report = finishRandomDryRun($service, $this->actor, $run['run_id']);
    expect($report['status'])->toBe('stopped')->and($report['selected_ids'])->toBe(['700'])
        ->and($report['errors'])->not->toBeEmpty();
});

it('hides Apply and downloads the existing report for an empty company', function () {
    $this->gateway->shouldReceive('invoiceCount')->once()->andReturn(0);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    Livewire\Livewire::test(QuickBooksSync::class)->assertActionHidden('applyThreeInvoices')
        ->callAction('testThreeInvoices')->assertSee('Test 50 Random Invoices')->assertSee('Seleccionadas')
        ->callAction('downloadSelectiveReport')->assertFileDownloaded('quickbooks_selective_test.json');
    expect(Invoice::count())->toBe(0);
});

it('rejects even a forged Apply ticket on the server before reading or importing anything', function () {
    $this->gateway->shouldNotReceive('invoiceCount');
    $this->gateway->shouldNotReceive('page');
    try {
        app(InvoiceSelectiveSyncService::class)->apply($this->actor, 'forged', true);
        $this->fail('Apply must be disabled');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }
    expect(Invoice::count())->toBe(0);
});

it('advances the existing Livewire action with short polls and never reveals Apply', function () {
    $this->gateway->shouldReceive('invoiceCount')->once()->andReturn(1);
    $this->gateway->shouldReceive('page')->with('Invoice', 1, 1)->once()->andReturn([randomDryRunPayload('810')]);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    $page = Livewire\Livewire::test(QuickBooksSync::class)->callAction('testThreeInvoices');
    for ($tick = 0; $tick < 8 && $page->get('selectiveRunId') !== null; $tick++) {
        $page->call('continueSelectiveDryRun')->assertActionHidden('applyThreeInvoices');
    }
    $page->assertSet('selectiveRunId', null)->assertSee('Pagada')->assertSee('Excluida')
        ->callAction('downloadSelectiveReport')->assertFileDownloaded('quickbooks_selective_test.json');
    expect($page->get('selectiveReport')['selected_ids'])->toBe(['810'])->and(Invoice::count())->toBe(0);
});
