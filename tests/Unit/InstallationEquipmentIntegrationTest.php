<?php

use App\Filament\Shared\Resources\EquipmentResource\Pages\CreateEquipment;
use App\Filament\Shared\Resources\EquipmentResource\Pages\EditEquipment;
use App\Filament\Shared\Resources\EquipmentResource\Pages\ListEquipment;
use App\Filament\Shared\Resources\EquipmentBrandResource\Pages\ManageEquipmentBrands;
use App\Filament\Shared\Resources\EquipmentModelResource\Pages\ManageEquipmentModels;
use App\Filament\Shared\Resources\InstallationResource\Pages\CreateInstallation;
use App\Filament\Shared\Resources\ManualResource\Pages\ListManuals;
use App\Filament\Shared\Resources\ManualResource\Pages\ViewManual;
use App\Filament\Shared\Resources\InstallationResource\Pages\EditInstallation;
use App\Filament\Shared\Resources\InstallationResource\Pages\ViewInstallation;
use App\Filament\Shared\Resources\ProductsResource\Pages\ListProducts;
use App\Filament\Worker\Pages\WorkerInstallationWizard;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentBrand;
use App\Models\EquipmentModel;
use App\Models\Manual;
use App\Models\InstalledProduct;
use App\Models\User;
use App\Services\InstallationProductService;
use App\Services\InstallationProgressService;
use App\Services\InstallationService;
use App\Services\DistributionSelectionService;
use App\Services\EquipmentOwnershipService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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

function equipmentCatalogUp(): void
{
    foreach (['2025_10_20_140936_create_manuals_table.php',
        '2026_11_27_120000_create_equipment_catalog_and_ownership.php',
        '2026_11_27_121000_backfill_equipment_catalog.php'] as $migration) {
        (require database_path('migrations/'.$migration))->up();
    }
}

it('protects the recorded customer and equipment while allowing other installation edits', function () {
    $installation = app(InstallationService::class)->create($this->installationData);
    app(InstallationProgressService::class)->startIfNeeded($installation);
    $product = traceProduct(null, ['assambled' => true, 'location_id' => 44, 'status_id' => 56]);
    $link = app(InstallationProductService::class)->register($installation, $product->serial, $this->worker);
    $otherEquipment = Equipment::create(['brand' => 'Other', 'model' => 'Fleet', 'company' => 'Buyer']);
    $otherCustomer = Customer::create(['display_name' => 'Other buyer', 'quickbooks_id' => '201']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(EditInstallation::class, ['record' => $installation->getRouteKey()])
        ->fillForm(['equipment_id' => $otherEquipment->id, 'customer_id' => $otherCustomer->id])
        ->call('save')->assertHasFormErrors(['equipment_id', 'customer_id']);

    $installation->refresh();
    expect($installation->equipment_id)->toBe($link->equipment_id)
        ->and($installation->customer_id)->toBe($link->customer_id)
        ->and($installation->assignment->equipment_id)->toBe($link->equipment_id)
        ->and($installation->assignment->customer_id)->toBe($link->customer_id);

    Livewire::test(EditInstallation::class, ['record' => $installation->getRouteKey()])
        ->fillForm(['performed_at' => '2026-11-27 10:00:00'])->call('save')->assertHasNoFormErrors();
    expect($installation->fresh()->performed_at->format('Y-m-d H:i'))->toBe('2026-11-27 10:00');
});

it('creates edits searches and displays optional free form equipment identifiers', function () {
    equipmentCatalogUp();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $model = $this->equipment->fresh()->catalogModel;
    $data = ['equipment_brand_id' => $model->equipment_brand_id, 'equipment_model_id' => $model->id,
        'customer_id' => $this->customer->id, 'company' => 'Buyer', 'equi_goods' => [], 'equi_asset' => []];
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

it('normalizes legacy catalogs and copies one shared guide without touching previous installations', function () {
    $legacy = $this->equipment;
    $same = Equipment::create(['brand' => ' historical ', 'model' => ' FLEET ', 'company' => 'Other', 'identifier' => 'B-2']);
    $empty = Equipment::create(['brand' => ' ', 'model' => 'Fleet', 'company' => 'Other']);
    $step = ['title' => 'Check', 'description' => 'Shared note', 'sort_order' => 2, 'is_required' => true, 'img' => 'equipment-steps/example.png'];
    $legacy->installationTemplateSteps()->create($step);
    $same->installationTemplateSteps()->create($step);
    $prior = app(InstallationService::class)->create($this->installationData);
    $before = $prior->steps()->firstOrFail()->getRawOriginal();

    equipmentCatalogUp();
    $legacy->refresh();
    $same->refresh();
    expect(EquipmentBrand::count())->toBe(1)->and(EquipmentModel::count())->toBe(1)
        ->and($legacy->equipment_model_id)->toBe($same->equipment_model_id)
        ->and($legacy->customer_id)->toBeNull()->and($same->identifier)->toBe('B-2')
        ->and($empty->fresh()->equipment_model_id)->toBeNull()
        ->and($legacy->installationTemplateSteps()->count())->toBe(1)
        ->and($legacy->catalogModel->templateSteps()->sole()->img)->toBe($step['img']);

    expect(fn () => app(InstallationService::class)->create($this->installationData))->toThrow(ValidationException::class);
    app(EquipmentOwnershipService::class)->transfer($same, $this->customer->id, $this->actor, 'Initial ownership');
    $legacy->catalogModel->templateSteps()->update(['title' => 'Updated check']);
    $new = app(InstallationService::class)->create(array_replace($this->installationData, ['equipment_id' => $same->id]));
    expect($new->steps()->sole()->title)->toBe('Updated check')
        ->and($prior->steps()->sole()->getRawOriginal())->toBe($before);

    $manual = Manual::create(['equipment_id' => $legacy->id, 'equipment_model_id' => $legacy->equipment_model_id, 'descript' => '<p>Original</p>']);
    $machineOnly = Manual::create(['equipment_id' => $legacy->id, 'descript' => '<p>Machine only</p>']);
    expect($same->catalogModel->manuals()->sole()->id)->toBe($manual->id)
        ->and($legacy->manual()->findOrFail($manual->id)->descript)->toBe('<p>Original</p>');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ListManuals::class)->filterTable('equipment', $same->id)
        ->assertCanSeeTableRecords([$manual])->assertCanNotSeeTableRecords([$machineOnly]);
    $modelOnly = Manual::create(['equipment_model_id' => $same->equipment_model_id, 'descript' => '<p>Shared content</p>']);
    Livewire::test(ViewManual::class, ['record' => $modelOnly->id])->assertSee('Shared content');
});

it('preserves divergent guides and blocks a new installation pending explicit model review', function () {
    $other = Equipment::create(['brand' => 'historical', 'model' => 'FLEET', 'company' => 'Buyer']);
    $this->equipment->installationTemplateSteps()->create(['title' => 'Guide A']);
    $other->installationTemplateSteps()->create(['title' => 'Guide B']);
    equipmentCatalogUp();
    $machine = $other->fresh();
    $model = $machine->catalogModel;
    expect($model->guide_needs_review)->toBeTrue()
        ->and($model->templateSteps()->count())->toBe(0)
        ->and($other->installationTemplateSteps()->sole()->title)->toBe('Guide B')
        ->and($this->equipment->installationTemplateSteps()->sole()->title)->toBe('Guide A');
    app(EquipmentOwnershipService::class)->transfer($machine, $this->customer->id, $this->actor);
    expect(fn () => app(InstallationService::class)->create(array_replace($this->installationData, ['equipment_id' => $machine->id])))
        ->toThrow(ValidationException::class);
    $model->templateSteps()->create(['title' => 'Reviewed guide']);
    $model->update(['guide_needs_review' => false]);
    $installation = app(InstallationService::class)->create(array_replace($this->installationData, ['equipment_id' => $machine->id]));
    expect($installation->steps()->sole()->title)->toBe('Reviewed guide');
});

it('enforces ownership transfer rules while preserving completed installations and installed products', function () {
    equipmentCatalogUp();
    $service = app(EquipmentOwnershipService::class);
    $machine = $this->equipment->fresh();
    $service->transfer($machine, $this->customer->id, $this->actor);
    $machine->refresh();
    $newOwner = Customer::create(['display_name' => 'New owner', 'quickbooks_id' => '201']);
    $installed = app(InstallationService::class)->create($this->installationData);
    expect(fn () => $service->transfer($machine, $newOwner->id, $this->actor))->toThrow(ValidationException::class);
    expect(fn () => $service->transfer($machine, $newOwner->id, $this->worker))->toThrow(AuthorizationException::class);

    app(InstallationProgressService::class)->startIfNeeded($installed);
    $product = traceProduct(null, ['assambled' => true, 'location_id' => 44, 'status_id' => 56]);
    $link = app(InstallationProductService::class)->register($installed, $product->serial, $this->worker);
    app(InstallationProgressService::class)->completeIfAllDone($installed);
    $prior = $installed->fresh()->getRawOriginal();
    $previousOrder = $installed->workOrder()->firstOrFail()->getRawOriginal();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(EditEquipment::class, ['record' => $machine->id])
        ->callAction('transferOwnership', ['customer_id' => $newOwner->id, 'reason' => 'Fleet sale'])
        ->assertHasNoActionErrors()->mountAction('ownershipHistory')->assertSee('Fleet sale');
    $transfer = $machine->ownershipTransfers()->firstOrFail();
    expect($machine->fresh()->customer_id)->toBe($newOwner->id)
        ->and($transfer->previous_customer_id)->toBe($this->customer->id)
        ->and($transfer->new_customer_id)->toBe($newOwner->id)
        ->and($transfer->user_id)->toBe($this->actor->id)
        ->and($transfer->reason)->toBe('Fleet sale')
        ->and($installed->fresh()->getRawOriginal())->toBe($prior)
        ->and($installed->workOrder()->firstOrFail()->getRawOriginal())->toBe($previousOrder)
        ->and($link->fresh()->customer_id)->toBe($this->customer->id)
        ->and($link->fresh()->serial_number)->toBe($product->serial);
    expect(fn () => $service->transfer($machine, $newOwner->id, $this->actor))->toThrow(ValidationException::class);
    expect(fn () => app(InstallationService::class)->create($this->installationData))->toThrow(ValidationException::class);
    $next = app(InstallationService::class)->create(array_replace($this->installationData, ['customer_id' => $newOwner->id]));
    expect($next->customer_id)->toBe($newOwner->id)->and($next->workOrder->customer_quickbooks_id)->toBe('201');
    expect(fn () => $service->update($machine, ['equipment_brand_id' => $machine->catalogModel->equipment_brand_id,
        'equipment_model_id' => $machine->equipment_model_id, 'identifier' => 'REPLACED'], $this->actor))->toThrow(ValidationException::class);
    expect(Gate::forUser($this->worker)->allows('create', Equipment::class))->toBeFalse()
        ->and(Gate::forUser($this->worker)->allows('update', $machine))->toBeFalse()
        ->and(Gate::forUser($this->actor)->allows('delete', $machine))->toBeFalse();
});

it('filters model choices by brand and prevents normalized duplicates and forged pairs', function () {
    equipmentCatalogUp();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ManageEquipmentBrands::class)->callAction('create', ['name' => '  Heavy   Duty '])->assertHasNoActionErrors();
    $brand = EquipmentBrand::where('name', 'Heavy Duty')->sole();
    Livewire::test(ManageEquipmentModels::class)->callAction('create', [
        'name' => '  Lift A  ', 'equipment_brand_id' => $brand->id,
    ])->assertHasNoActionErrors();
    $first = $brand->models()->sole();
    $other = EquipmentBrand::create(['name' => 'Other']);
    $second = $other->models()->create(['name' => 'Lift A']);
    expect($brand->fresh()->name)->toBe('Heavy Duty')
        ->and($first->fresh()->name)->toBe('Lift A');
    expect(fn () => EquipmentBrand::create(['name' => 'heavy duty']))->toThrow(QueryException::class);
    expect(fn () => $brand->models()->create(['name' => 'LIFT   A']))->toThrow(QueryException::class);
    $page = Livewire::test(CreateEquipment::class)->fillForm(['equipment_brand_id' => $brand->id]);
    $options = $page->instance()->getFormSelectOptions('data.equipment_model_id');
    expect(array_map('intval', array_column($options, 'value')))->toBe([$first->id]);
    expect(fn () => app(EquipmentOwnershipService::class)->create(['equipment_brand_id' => $brand->id,
        'equipment_model_id' => $second->id, 'company' => 'Buyer', 'customer_id' => $this->customer->id], $this->actor))
        ->toThrow(ValidationException::class);
});

it('copies the published model guide from the new Installation form', function () {
    $this->equipment->installationTemplateSteps()->create(['title' => 'Model checklist', 'sort_order' => 1]);
    equipmentCatalogUp();
    $machine = $this->equipment->fresh();
    app(EquipmentOwnershipService::class)->transfer($machine, $this->customer->id, $this->actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(CreateInstallation::class)
        ->fillForm($this->installationData)
        ->set('data.equipment_id', (string) $machine->id)
        ->call('create')->assertHasNoFormErrors();
    expect($machine->installations()->sole()->steps()->sole()->title)->toBe('Model checklist');
});
