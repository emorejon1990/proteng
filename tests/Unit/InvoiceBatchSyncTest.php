<?php

use App\Filament\Admin\Pages\QuickBooksSync;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceEvent;
use App\Models\User;
use App\Services\InstallationService;
use App\Services\InvoiceBatchSyncService;
use App\Services\InvoiceClassificationService;
use App\Services\InvoiceSyncService;
use App\Services\QuickBooksReadGateway;
use App\Services\QuickBooksService;
use App\Services\QuickBooksSyncLock;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
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
    (require database_path('migrations/2026_10_27_120000_create_invoice_sync_sessions_table.php'))->up();
    config(['permission.cache.store' => 'array', 'services.quickbooks.invoice_batch_size' => 5, 'cache.default' => 'array']);
    Cache::flush();
    app(PermissionRegistrar::class)->initializeCache();
    foreach (['Admin', 'Manager', 'Worker', 'Customer'] as $role) {
        Role::create(['name' => $role, 'guard_name' => 'web']);
    }
    $this->actor = User::findOrFail(1)->assignRole('Admin');
    $this->actingAs($this->actor);
    Customer::create(['quickbooks_id' => '10', 'display_name' => 'Test']);
    DB::table('asset')->where('id', 31)->update(['quickbooks_id' => '100']);
    Http::preventStrayRequests();
    $qb = Mockery::mock(QuickBooksService::class);
    $qb->shouldNotReceive('ds');
    $this->instance(QuickBooksService::class, $qb);
    $this->gateway = Mockery::mock(QuickBooksReadGateway::class);
    $this->realm = 'fixture-realm';
    $this->gateway->shouldReceive('realm')->andReturnUsing(fn () => $this->realm);
    $this->gateway->shouldReceive('find')->with('Item', '100')->andReturn(['Id' => '100', 'Type' => 'Inventory', 'ParentRef' => '900'])->byDefault();
    $this->gateway->shouldReceive('find')->with('Item', '900')->andReturn(['Id' => '900', 'Type' => 'Category', 'Name' => 'Product'])->byDefault();
    $this->payloads = [];
    for ($id = 500; $id < 512; $id++) {
        $this->payloads[] = batchInvoicePayload((string) $id);
    }
    $this->gateway->shouldReceive('page')->with('Invoice', Mockery::type('int'), Mockery::type('int'))
        ->andReturnUsing(fn ($entity, $position, $size) => array_slice($this->payloads, $position - 1, $size))->byDefault();
    $this->gateway->shouldReceive('find')->with('Invoice', Mockery::any())->andReturnUsing(function ($entity, $id) {
        return collect($this->payloads)->firstWhere('Id', $id) ?? batchInvoicePayload($id);
    })->byDefault();
    $this->instance(QuickBooksReadGateway::class, $this->gateway);
});

afterEach(fn () => DB::disconnect('sqlite'));

function batchInvoicePayload(string $id): array
{
    return ['Id' => $id, 'DocNumber' => '000-'.$id.'-A', 'CustomerRef' => '10', 'TotalAmt' => 10, 'Balance' => 10, 'TxnDate' => '2026-10-27',
        'Line' => [['Id' => '1', 'Amount' => 10, 'DetailType' => 'SalesItemLineDetail',
            'SalesItemLineDetail' => ['ItemRef' => '100', 'Qty' => 1]]]];
}

function finishBatch(User $actor, string $run): array
{
    for ($i = 0; $i < 30; $i++) {
        $state = app(InvoiceBatchSyncService::class)->advance($actor, $run);
        if ($state['status'] !== 'running') {
            return $state;
        }
    }
    throw new RuntimeException('Exceeded block budget.');
}

it('imports multiple independent blocks of at most five and emits one final event', function () {
    $service = app(InvoiceBatchSyncService::class);
    $state = $service->start($this->actor);
    expect(Invoice::count())->toBe(0);
    for ($i = 0; $i < 3; $i++) {
        $state = $service->advance($this->actor, $state['run_id']);
    }
    expect($state['phase'])->toBe('import')->and(Invoice::count())->toBe(0);
    $state = $service->advance($this->actor, $state['run_id']);
    expect($state['summary']['processed'])->toBe(5)->and(Invoice::count())->toBe(5);
    $state = finishBatch($this->actor, $state['run_id']);
    expect($state['status'])->toBe('completed')->and($state['summary']['synced'])->toBe(12)->and(Invoice::count())->toBe(12);
    $service->advance($this->actor, $state['run_id']);
    expect(InvoiceEvent::where('event', 'Invoice Batch Sync Completed')->count())->toBe(1);
});

it('resumes persisted progress after a new request without duplicating imported invoices', function () {
    $service = app(InvoiceBatchSyncService::class);
    $state = $service->start($this->actor);
    for ($i = 0; $i < 4; $i++) {
        $state = $service->advance($this->actor, $state['run_id']);
    }
    $this->app->forgetScopedInstances();
    $service = app(InvoiceBatchSyncService::class);
    $resumed = $service->start($this->actor);
    expect($resumed['run_id'])->toBe($state['run_id'])->and($resumed['cursor'])->toBe(5);
    finishBatch($this->actor, $resumed['run_id']);
    $next = $service->start($this->actor);
    finishBatch($this->actor, $next['run_id']);
    expect(Invoice::count())->toBe(12)->and(InvoiceEvent::where('event', 'Invoice Imported')->count())->toBe(12);
});

it('reads current invoice identities and balances rather than stale discovery payloads', function () {
    $this->payloads = [batchInvoicePayload('500')];
    $service = app(InvoiceBatchSyncService::class);
    $state = $service->start($this->actor);
    $service->advance($this->actor, $state['run_id']);
    $this->payloads[0]['Balance'] = 0;
    $state = finishBatch($this->actor, $state['run_id']);
    expect($state['summary']['excluded'])->toBe(1)->and(Invoice::count())->toBe(0);
});

it('stops on mismatched invoice identity and retries the same ID after recovery', function () {
    $this->payloads = [batchInvoicePayload('500')];
    $bad = true;
    // A mutable reference lets the simulated API recover without replacing the service.
    $this->gateway->shouldReceive('find')->with('Invoice', '500')->andReturnUsing(function () use (&$bad) {
        return batchInvoicePayload($bad ? '999' : '500');
    });
    $service = app(InvoiceBatchSyncService::class);
    $state = $service->start($this->actor);
    $state = finishBatch($this->actor, $state['run_id']);
    expect($state['status'])->toBe('stopped')->and($state['cursor'])->toBe(0)->and(Invoice::count())->toBe(0);
    $bad = false;
    $state = $service->start($this->actor);
    expect(finishBatch($this->actor, $state['run_id'])['status'])->toBe('completed');
});

it('preserves committed progress on API failure and resumes at the failed record', function () {
    $fail = true;
    $this->gateway->shouldReceive('find')->with('Invoice', '502')->andReturnUsing(function () use (&$fail) {
        if ($fail) {
            throw new RuntimeException('Simulated API timeout');
        }

        return batchInvoicePayload('502');
    });
    $service = app(InvoiceBatchSyncService::class);
    $state = $service->start($this->actor);
    $state = finishBatch($this->actor, $state['run_id']);
    expect($state['status'])->toBe('stopped')->and($state['cursor'])->toBe(2)->and(Invoice::count())->toBe(2);
    $fail = false;
    $state = $service->start($this->actor);
    expect(finishBatch($this->actor, $state['run_id'])['summary']['synced'])->toBe(12)->and(Invoice::count())->toBe(12);
});

it('uses unchanged exclusion and review filters and continues past invalid invoice data', function () {
    $this->payloads = array_slice($this->payloads, 0, 4);
    $this->payloads[0]['Balance'] = 0;
    $this->payloads[1]['Balance'] = 99;
    $this->payloads[2]['TxnDate'] = null;
    $service = app(InvoiceBatchSyncService::class);
    $state = $service->start($this->actor);
    $state = finishBatch($this->actor, $state['run_id']);
    expect($state['summary'])->toBe(['processed' => 4, 'synced' => 1, 'excluded' => 1, 'failed' => 1, 'review' => 1]);
});

it('preserves imported linked orders snapshots invoice numbers and line identities', function (string $type) {
    $invoice = app(InvoiceSyncService::class)->import($this->payloads[0], 'fixture-realm');
    $installation = null;
    if ($type === 'installation') {
        $equipment = DB::table('equipment')->insertGetId(['brand' => 'Test', 'model' => 'Test', 'company' => 'Test']);
        $installation = app(InstallationService::class)->create(['customer_id' => $invoice->customer_id,
            'equipment_id' => $equipment, 'inst_manager_user_id' => $this->actor->id, 'worker_user_id' => $this->actor->id, 'status' => 'draft']);
        $order = $installation->workOrder;
        app(InvoiceClassificationService::class)->assignToInstallation($order->id, $invoice->id, $this->actor);
    } else {
        $order = app(InvoiceClassificationService::class)->classify($invoice->id, 'distribution', $this->actor);
    }
    $originalOrder = $order->fresh()->getRawOriginal();
    $originalInstallation = $installation?->getRawOriginal();
    $snapshot = $invoice->fresh()->operation_snapshot;
    $lineId = $invoice->lines()->first()->id;
    $this->payloads = []; // Also exercises reconciliation of locally imported invoices absent from the listing.
    $service = app(InvoiceBatchSyncService::class);
    $state = $service->start($this->actor);
    finishBatch($this->actor, $state['run_id']);
    expect($invoice->fresh()->work_order_id)->toBe($order->id)->and($invoice->fresh()->operation_snapshot)->toBe($snapshot)
        ->and($invoice->fresh()->invoice_number)->toBe('000-500-A')->and($invoice->lines()->first()->id)->toBe($lineId);
    expect($order->fresh()->getRawOriginal())->toBe($originalOrder)
        ->and($installation?->fresh()->getRawOriginal())->toBe($originalInstallation);
})->with(['distribution', 'installation']);

it('authorizes only Admin for every service entry point and the page', function (string $role) {
    $this->actor->syncRoles([$role]);
    $service = app(InvoiceBatchSyncService::class);
    expect(fn () => $service->start($this->actor))->toThrow(HttpException::class)
        ->and(fn () => $service->advance($this->actor, 'fake'))->toThrow(HttpException::class)
        ->and(fn () => $service->current($this->actor))->toThrow(HttpException::class)
        ->and(QuickBooksSync::canAccess())->toBeFalse();
})->with(['Worker', 'Manager', 'Customer']);

it('rejects simultaneous requests and reserves the interval between blocks against legacy sync', function () {
    $service = app(InvoiceBatchSyncService::class);
    $state = $service->start($this->actor);
    $lock = Cache::lock(QuickBooksSyncLock::KEY, 120);
    $lock->get();
    expect(fn () => $service->advance($this->actor, $state['run_id']))->toThrow(RuntimeException::class);
    $lock->release();
    expect(fn () => app(InvoiceSyncService::class)->sync($this->actor))->toThrow(RuntimeException::class);
    expect($service->start($this->actor)['run_id'])->toBe($state['run_id']);
    $this->travel(11)->minutes();
    expect(app(QuickBooksSyncLock::class)->run(fn () => 'released'))->toBe('released');
});

it('refuses a changed company or environment before importing', function () {
    $service = app(InvoiceBatchSyncService::class);
    $state = $service->start($this->actor);
    $this->realm = 'other-company';
    $state = $service->advance($this->actor, $state['run_id']);
    expect($state['status'])->toBe('stopped')->and(Invoice::count())->toBe(0);
});

it('flags changed requirements between blocks without changing a linked Work Order', function () {
    $this->payloads = [batchInvoicePayload('500')];
    $invoice = app(InvoiceSyncService::class)->import($this->payloads[0], 'fixture-realm');
    $order = app(InvoiceClassificationService::class)->classify($invoice->id, 'distribution', $this->actor);
    $snapshot = $invoice->fresh()->operation_snapshot;
    $orderBefore = $order->fresh()->getRawOriginal();
    $service = app(InvoiceBatchSyncService::class);
    $state = $service->start($this->actor);
    $service->advance($this->actor, $state['run_id']);
    $this->payloads[0]['Line'][0]['SalesItemLineDetail']['Qty'] = 2;
    finishBatch($this->actor, $state['run_id']);
    expect($invoice->fresh()->operation_status)->toBe('sync_conflict')
        ->and($invoice->fresh()->work_order_id)->toBe($order->id)
        ->and($invoice->fresh()->operation_snapshot)->toBe($snapshot)
        ->and($order->fresh()->getRawOriginal())->toBe($orderBefore)
        ->and($order->workOrderAssets()->first()->quantity)->toBe(1);
});

it('rolls back import and cursor together when the checkpoint cannot be saved', function () {
    $this->payloads = [batchInvoicePayload('500')];
    $service = app(InvoiceBatchSyncService::class);
    $state = $service->start($this->actor);
    $service->advance($this->actor, $state['run_id']);
    DB::statement("CREATE TRIGGER fail_batch_checkpoint BEFORE UPDATE ON invoice_sync_sessions WHEN json_extract(NEW.state, '$.cursor') > 0 BEGIN SELECT RAISE(ABORT, 'test checkpoint failure'); END");
    $state = $service->advance($this->actor, $state['run_id']);
    expect($state['status'])->toBe('stopped')->and($state['cursor'])->toBe(0)->and(Invoice::count())->toBe(0);
    DB::statement('DROP TRIGGER fail_batch_checkpoint');
    $state = $service->start($this->actor);
    expect(finishBatch($this->actor, $state['run_id'])['summary']['synced'])->toBe(1);
});

it('continues from the Admin page with separate Livewire requests', function () {
    $this->payloads = [batchInvoicePayload('500')];
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    $page = Livewire\Livewire::test(QuickBooksSync::class)->callAction('syncInvoices');
    expect(Invoice::count())->toBe(0);
    $page->call('continueInvoiceSync')->assertSee('Importing invoices');
    $page->call('continueInvoiceSync')->assertSee('completed');
    expect(Invoice::count())->toBe(1);
    Livewire\Livewire::test(QuickBooksSync::class)->assertSee('completed');
});

it('releases an expired block lease and resumes without committing stale writes', function () {
    $this->payloads = [batchInvoicePayload('500')];
    $this->gateway->shouldReceive('find')->with('Invoice', '500')->once()->andReturnUsing(function () {
        // ArrayLock uses wall time; simulate the backend expiring this lease.
        Cache::lock(QuickBooksSyncLock::KEY)->forceRelease();

        return batchInvoicePayload('500');
    });
    $service = app(InvoiceBatchSyncService::class);
    $state = $service->start($this->actor);
    $service->advance($this->actor, $state['run_id']);
    expect(fn () => $service->advance($this->actor, $state['run_id']))->toThrow(RuntimeException::class);
    expect(Invoice::count())->toBe(0)->and($service->current($this->actor)['cursor'])->toBe(0);
    expect($service->start($this->actor)['run_id'])->toBe($state['run_id']);
});

it('shows the final partial counts in the Admin notification', function () {
    $this->payloads = [batchInvoicePayload('500')];
    $this->payloads[0]['Balance'] = 99;
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    Livewire\Livewire::test(QuickBooksSync::class)->callAction('syncInvoices')
        ->call('continueInvoiceSync')->call('continueInvoiceSync')
        ->assertNotified(Notification::make()->title('Invoice sync partially completed')
            ->body('0 synchronized; 0 excluded; 0 failed; 1 require review. Review Invoice Sync Events.')->warning());
});
