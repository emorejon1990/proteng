<?php

use App\Filament\Shared\Resources\EquipmentResource\Pages\CreateEquipment;
use App\Filament\Shared\Resources\EquipmentResource\Pages\EditEquipment;
use App\Filament\Shared\Resources\EquipmentResource\Pages\ListEquipment;
use App\Filament\Shared\Resources\InstallationResource\Pages\ViewInstallation;
use App\Filament\Shared\Resources\ProductsResource\Pages\ListProducts;
use App\Filament\Worker\Pages\WorkerInstallationWizard;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\InstalledProduct;
use App\Models\User;
use App\Services\InstallationProductService;
use App\Services\InstallationProgressService;
use App\Services\InstallationService;
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
require_once __DIR__.'/../Support/InvoiceDatabase.php';

beforeEach(function () {
    setupTraceabilityDatabase();
    invoiceIntegrationUp();
    phaseOneUp();
    config(['permission.cache.store' => 'array', 'production.catalog_ids' => []]);
    app(PermissionRegistrar::class)->initializeCache();
    foreach (['Admin', 'Worker', 'Inst_Manager'] as $role) {
        Role::create(['name' => $role, 'guard_name' => 'web']);
    }
    $this->actor = User::findOrFail(1)->assignRole('Admin');
    $this->actingAs($this->actor);
    $this->worker = User::create(['name' => 'Worker', 'email' => 'installation-worker@example.test', 'password' => 'test'])->assignRole('Worker');
    $this->manager = User::create(['name' => 'Installer', 'email' => 'installation-manager@example.test', 'password' => 'test'])->assignRole('Inst_Manager');
    $this->equipment = Equipment::create(['brand' => 'Historical', 'model' => 'Fleet', 'company' => 'Buyer']);
    $this->customer = Customer::create(['display_name' => 'Buyer', 'quickbooks_id' => '200']);
    DB::table('locations')->insert(['id' => 44, 'name' => 'Stock']);
    DB::table('status')->insert(['id' => 56, 'name' => 'Ready']);
    DB::table('wo_status')->insert([['id' => 13, 'name' => 'inProcess'], ['id' => 16, 'name' => 'Ready']]);

    foreach (['2025_10_20_141101_create_goods_table.php', '2025_10_20_141305_create_equi_goods_table.php',
        '2025_11_03_161746_create_equi_asset_table.php', '2026_01_08_142455_create_installed_products_table.php',
        '2026_10_13_130000_add_distribution_location.php', '2026_10_13_130100_add_distribution_selection_snapshot.php',
        '2026_11_27_100000_add_identifier_to_equipment_table.php',
        '2026_11_27_110000_add_traceability_to_installed_products_table.php',
        '2026_11_27_111000_add_installed_location.php'] as $file) {
        (require database_path('migrations/'.$file))->up();
    }
    $this->installationData = ['customer_id' => $this->customer->id, 'equipment_id' => $this->equipment->id,
        'status' => 'draft', 'worker_user_id' => $this->worker->id, 'inst_manager_user_id' => $this->manager->id];
});

it('installs a dispatched serial only for the matching customer and shows its history in all three resources', function () {
    $installation = app(InstallationService::class)->create($this->installationData);
    Customer::create(['display_name' => 'Other buyer', 'quickbooks_id' => 'different-customer']);
    $order = traceOrder(23);
    $order->update(['status_id' => 13, 'customer_quickbooks_id' => 'different-customer']);
    DB::table('wo_ass')->insert(['work_order_id' => $order->id, 'asset_id' => 31, 'quantity' => 1]);
    $product = traceProduct(null, ['assambled' => true, 'location_id' => 44, 'status_id' => 56]);
    $distribution = app(DistributionSelectionService::class);
    $distribution->reserve($order->id, 31, $product->serial, $this->actor);
    $distribution->confirm($order->id, $this->actor);
    app(InstallationProgressService::class)->startIfNeeded($installation);
    expect(fn () => app(InstallationProductService::class)->register($installation, $product->serial, $this->worker))
        ->toThrow(ValidationException::class);

    $order->update(['customer_quickbooks_id' => $this->customer->quickbooks_id]);
    $this->actingAs($this->worker);
    Filament::setCurrentPanel(Filament::getPanel('worker'));
    Livewire::test(WorkerInstallationWizard::class)
        ->set('installationSerial', $product->serial)->call('registerInstalledProduct')
        ->assertHasNoErrors()->assertSee($product->serial);

    $this->actingAs($this->actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ViewInstallation::class, ['record' => $installation->getRouteKey()])->assertSee($product->serial);
    Livewire::test(ListEquipment::class)->mountTableAction('installedProducts', $this->equipment)->assertSee($product->serial);
    Livewire::test(ListProducts::class)->mountTableAction('traceability', $product->fresh())
        ->assertSee('Installation registered')->assertSee('Distribution confirmed');
});

afterEach(fn () => DB::disconnect('sqlite'));

it('creates edits searches and displays optional free form equipment identifiers', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $data = ['brand' => 'Lift', 'model' => 'X1', 'company' => 'Buyer', 'installationTemplateSteps' => [], 'equi_goods' => [], 'equi_asset' => []];
    foreach (['FLEET / 12-Z', 'FLEET / 12-Z', null] as $identifier) {
        Livewire::test(CreateEquipment::class)->fillForm($data + ['identifier' => $identifier])->call('create')->assertHasNoFormErrors();
    }
    expect($this->equipment->fresh()->identifier)->toBeNull();
    expect(Equipment::where('identifier', 'FLEET / 12-Z')->count())->toBe(2);
    $record = Equipment::whereNotNull('identifier')->firstOrFail();
    $vin = '1HGCM82633A004352';
    Livewire::test(EditEquipment::class, ['record' => $record->getRouteKey()])
        ->fillForm(['identifier' => $vin])->call('save')->assertHasNoFormErrors();
    Livewire::test(ListEquipment::class)->searchTable($vin)->assertCanSeeTableRecords([$record->fresh()])
        ->assertCanNotSeeTableRecords([$this->equipment])
        ->mountTableAction('view', $record->fresh())->assertSee($vin);
});

it('records stock once and keeps equipment customer order inventory and audit consistent', function () {
    $installation = app(InstallationService::class)->create($this->installationData);
    app(InstallationProgressService::class)->startIfNeeded($installation);
    $product = traceProduct(null, ['assambled' => true, 'location_id' => 44, 'status_id' => 56]);
    $service = app(InstallationProductService::class);
    $record = $service->register($installation, strtolower($product->serial), $this->worker);
    expect($service->register($installation, $product->serial, $this->worker)->id)->toBe($record->id)
        ->and($record->product_id)->toBe($product->id)
        ->and($record->serial_number)->toBe($product->serial)
        ->and($record->customer_id)->toBe($this->customer->id)
        ->and($record->equipment_id)->toBe($this->equipment->id)
        ->and($record->registered_by)->toBe($this->worker->id)
        ->and($record->work_order_id)->toBe($installation->work_order_id)
        ->and($installation->installedProducts()->count())->toBe(1)
        ->and($this->equipment->installedProducts()->count())->toBe(1)
        ->and($product->installedProducts()->count())->toBe(1)
        ->and($product->fresh()->status->name)->toBe('Installed')
        ->and($product->fresh()->location->name)->toBe('Installed')
        ->and($product->history()->where('process', 'Installation registered')->count())->toBe(1);
    $otherEquipment = Equipment::create(['brand' => 'Another', 'model' => 'Y', 'company' => 'Buyer']);
    $other = app(InstallationService::class)->create(array_replace($this->installationData, ['equipment_id' => $otherEquipment->id]));
    app(InstallationProgressService::class)->startIfNeeded($other);
    expect(fn () => $service->register($other, $product->serial, $this->actor))->toThrow(ValidationException::class);
});

it('rejects unauthorized operators and rolls inventory and the installed row back when audit fails', function () {
    $installation = app(InstallationService::class)->create($this->installationData);
    app(InstallationProgressService::class)->startIfNeeded($installation);
    $product = traceProduct(null, ['assambled' => true, 'location_id' => 44, 'status_id' => 56]);
    $unassigned = User::create(['name' => 'Other', 'email' => 'other-installer@example.test', 'password' => 'test'])->assignRole('Worker');
    $service = app(InstallationProductService::class);
    expect(fn () => $service->register($installation, $product->serial, $unassigned))->toThrow(HttpException::class);
    DB::statement('CREATE TRIGGER fail_installation_audit BEFORE INSERT ON history BEGIN SELECT RAISE(ABORT, "audit failed"); END');
    expect(fn () => $service->register($installation, $product->serial, $this->actor))->toThrow(QueryException::class);
    expect(InstalledProduct::count())->toBe(0)->and($product->fresh()->location_id)->toBe(44)
        ->and($product->fresh()->status_id)->toBe(56)->and($product->fresh()->work_order_id)->toBeNull();
});

it('allows at most one installation to commit when two operators scan the same serial concurrently', function () {
    if (! function_exists('proc_open')) {
        $this->markTestSkipped('Requires independent test processes.');
    }

    $first = app(InstallationService::class)->create($this->installationData);
    $equipment = Equipment::create(['brand' => 'Other', 'model' => 'Fleet', 'company' => 'Buyer']);
    $second = app(InstallationService::class)->create(array_replace($this->installationData, ['equipment_id' => $equipment->id]));
    foreach ([$first, $second] as $installation) {
        app(InstallationProgressService::class)->startIfNeeded($installation);
    }
    $product = traceProduct(null, ['assambled' => true, 'location_id' => 44, 'status_id' => 56]);
    $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'proteng-installation-test-'.bin2hex(random_bytes(12)).'.sqlite';
    $workers = [];

    try {
        DB::statement('VACUUM INTO ?', [$file]);
        foreach ([$first, $second] as $installation) {
            $process = proc_open([PHP_BINARY, base_path('tests/Support/installation-concurrency-worker.php')],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start an isolated installation worker.');
            }
            $workers[] = [$process, $pipes];
            fwrite($pipes[0], json_encode(['file' => $file, 'installation' => $installation->id, 'serial' => $product->serial], JSON_THROW_ON_ERROR)."\n");
            fflush($pipes[0]);
            stream_set_timeout($pipes[1], 30);
            expect(trim(fgets($pipes[1]) ?: ''))->toBe('READY');
        }
        foreach ($workers as [$process, $pipes]) {
            fwrite($pipes[0], "GO\n");
            fclose($pipes[0]);
        }
        $outcomes = [];
        foreach ($workers as [$process, $pipes]) {
            $outcomes[] = trim(fgets($pipes[1]) ?: '');
            expect(stream_get_contents($pipes[2]))->toBe('');
            fclose($pipes[1]);
            fclose($pipes[2]);
            expect(proc_close($process))->toBe(0);
        }
        expect(array_count_values($outcomes)['DONE'] ?? 0)->toBe(1);
        foreach ($outcomes as $outcome) {
            expect($outcome)->toBeIn(['DONE', 'REJECTED', 'BLOCKED']);
        }
        $database = new PDO('sqlite:'.$file);
        expect((int) $database->query('select count(*) from installed_products')->fetchColumn())->toBe(1);
        expect((int) $database->query("select count(*) from history where process = 'Installation registered'")->fetchColumn())->toBe(1);
        $database = null;
    } finally {
        foreach ($workers as [$process, $pipes]) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        if (is_file($file)) {
            unlink($file);
        }
    }
});
