<?php

use App\Filament\Admin\Pages\GenerateSerials;
use App\Filament\Admin\Pages\RefillProd;
use App\Http\Controllers\SerialController;
use App\Models\Products;
use App\Models\RefillEntry;
use App\Models\RefillOperation;
use App\Models\Serial;
use App\Models\User;
use App\Services\DistributionSelectionService;
use App\Services\ExistingProductsRefillService;
use App\Services\SerialGenerationService;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

require_once __DIR__.'/../Support/ProductionDatabase.php';
uses(TestCase::class);

beforeEach(function () {
    setupTraceabilityDatabase();
    (require database_path('migrations/2025_10_02_192820_create_permission_tables.php'))->up();
    config(['permission.cache.store' => 'array']);
    app(PermissionRegistrar::class)->initializeCache();
    foreach (['Admin', 'Manager', 'Worker', 'Customer'] as $role) {
        Role::create(['name' => $role, 'guard_name' => 'web']);
    }
    $this->actor = User::findOrFail(1)->assignRole('Admin');
    $this->actingAs($this->actor);
    phaseOneUp();
    (require database_path('migrations/2026_10_28_100000_create_refill_operations.php'))->up();
    DB::table('status')->insert([['id' => 61, 'name' => 'Ready'], ['id' => 62, 'name' => 'inProcess']]);
    DB::table('locations')->insert([['id' => 51, 'name' => 'Stock'], ['id' => 52, 'name' => 'Distribution']]);
    $this->refillData = ['asset_id' => 31, 'assambly_by' => 1, 'fill_by' => 1, 'quality_by' => 1,
        'assambled' => true, 'filled' => true, 'qualified' => true, 'status_id' => 61, 'location_id' => 51, 'weight' => 2500];
});

afterEach(fn () => DB::disconnect('sqlite'));

it('manually generates exactly the requested unique Free serials without products or Work Orders', function () {
    $records = app(SerialGenerationService::class)->generate(20, $this->actor);
    expect($records)->toHaveCount(20)->and($records->pluck('serial')->unique())->toHaveCount(20)
        ->and(DB::table('products')->count())->toBe(0)->and(DB::table('work_orders')->count())->toBe(0);
    foreach ($records as $record) {
        expect($record->serial)->toMatch('/^[A-Z][0-9]{7}[A-Z]$/')->and($record->status)->toBe(Serial::STATUS_FREE)
            ->and($record->products_id)->toBeNull();
    }
});

it('validates manual generation quantities on the server', function ($value) {
    expect(fn () => app(SerialGenerationService::class)->generate($value, $this->actor))->toThrow(ValidationException::class)
        ->and(Serial::count())->toBe(0);
})->with([0, -1, 1.5, 'abc', 1001, null]);

it('restricts manual serial generation to Admin', function (string $role) {
    $this->actor->syncRoles([$role]);
    expect(GenerateSerials::canAccess())->toBeFalse()
        ->and(fn () => app(SerialGenerationService::class)->generate(1, $this->actor))->toThrow(HttpException::class);
})->with(['Manager', 'Worker', 'Customer']);

it('generates serials from the independent Admin page', function () {
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    Livewire\Livewire::test(GenerateSerials::class)->fillForm(['quantity' => 3])->call('generate')
        ->assertHasNoFormErrors()->assertNotified(Notification::make()->title('3 serials generated')->success());
    expect(Serial::count())->toBe(3);
});

it('rolls back manual generation if storing a serial fails', function () {
    DB::statement("CREATE TRIGGER fail_manual_serial BEFORE INSERT ON serial BEGIN SELECT RAISE(ABORT, 'test failure'); END");
    expect(fn () => app(SerialGenerationService::class)->generate(5, $this->actor))->toThrow(QueryException::class)
        ->and(Serial::count())->toBe(0);
});

it('persists each Free scan immediately with its form data serial link and Stock history', function () {
    $serial = SerialController::generatepool(1)->first();
    $entry = app(ExistingProductsRefillService::class)->scan($this->refillData, strtolower($serial->serial)."\r\n", null, $this->actor);
    $product = $entry->product;
    expect(Products::count())->toBe(1)->and($product->serial)->toBe($serial->serial)
        ->and($product->location_id)->toBe(51)->and($product->status_id)->toBe(61)
        ->and($product->assambly_by)->toBe(1)->and($product->fill_by)->toBe(1)->and($product->quality_by)->toBe(1)
        ->and($product->weight)->toBe(2500.0)->and($product->f_weight)->toBe(2500.0)
        ->and($product->assambled)->toBeTrue()->and($product->filled)->toBeTrue()->and($product->qualified)->toBeTrue()
        ->and($product->quality_date)->not->toBeNull()->and($product->work_order_id)->toBeNull()
        ->and($product->production_work_order_id)->toBeNull()->and(DB::table('work_orders')->count())->toBe(0)
        ->and($serial->fresh()->status)->toBe(Serial::STATUS_USED)->and($serial->fresh()->products_id)->toBe($product->id)
        ->and($product->history()->count())->toBe(1)->and(RefillOperation::first()->user_id)->toBe($this->actor->id);
    expect(app(DistributionSelectionService::class)->available()->whereKey($product->id)->exists())->toBeTrue();
});

it('rejects repeated scans unknown serials reserved serials used serials and linked historical products', function (string $mode) {
    $serial = SerialController::generatepool(1)->first();
    if ($mode === 'repeat') {
        app(ExistingProductsRefillService::class)->scan($this->refillData, $serial->serial, null, $this->actor);
    } elseif ($mode === 'reserved') {
        $serial->update(['status' => Serial::STATUS_RESERVED]);
    } elseif ($mode === 'used') {
        $serial->update(['status' => Serial::STATUS_USED]);
    } elseif ($mode === 'linked') {
        $product = traceProduct();
        $serial->update(['products_id' => $product->id]);
    } elseif ($mode === 'historical') {
        traceProduct(null, ['serial' => $serial->serial]);
    }
    $before = Products::count();
    expect(fn () => app(ExistingProductsRefillService::class)->scan($this->refillData, $mode === 'unknown' ? 'Z9999999Z' : $serial->serial, null, $this->actor))
        ->toThrow(ValidationException::class)->and(Products::count())->toBe($before);
})->with(['repeat', 'reserved', 'used', 'linked', 'historical', 'unknown']);

it('requires finished products valid employees positive weight and the real Ready Stock catalogs', function (array $change) {
    $serial = SerialController::generatepool(1)->first();
    expect(fn () => app(ExistingProductsRefillService::class)->scan(array_replace($this->refillData, $change), $serial->serial, null, $this->actor))
        ->toThrow(ValidationException::class)->and(Products::count())->toBe(0)
        ->and($serial->fresh()->status)->toBe(Serial::STATUS_FREE);
})->with([[['qualified' => false]], [['filled' => false]], [['assambled' => false]], [['weight' => 0]], [['assambly_by' => 999]], [['status_id' => 62]], [['location_id' => 52]]]);

it('recovers the operation after page reload and resets the scanner after each immediate save', function () {
    $serials = SerialController::generatepool(2);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    $page = Livewire\Livewire::test(RefillProd::class)->fillForm($this->refillData)
        ->set('serialInput', $serials[0]->serial)->call('scan')->assertHasNoErrors()->assertSet('serialInput', '')
        ->assertSee($serials[0]->serial)->assertSee('Products registered: 1');
    expect(Products::count())->toBe(1);
    $page->set('serialInput', $serials[1]->serial)->call('scan')->assertHasNoErrors()->assertSee('Products registered: 2');
    Livewire\Livewire::test(RefillProd::class)->assertSee($serials[0]->serial)->assertSee($serials[1]->serial)
        ->assertSet('operationId', RefillOperation::first()->id);
    expect(RefillOperation::count())->toBe(1)->and(Products::count())->toBe(2);
});

it('isolates users and operations and does not trust submitted operation or entry IDs', function () {
    $serials = SerialController::generatepool(2);
    $service = app(ExistingProductsRefillService::class);
    $first = $service->scan($this->refillData, $serials[0]->serial, null, $this->actor);
    $other = User::create(['name' => 'Other', 'email' => 'other@test.test', 'password' => 'test'])->assignRole('Admin');
    $second = $service->scan($this->refillData, $serials[1]->serial, null, $other);
    expect($service->operations($other)->count())->toBe(1);
    expect(fn () => $service->delete($first->refill_operation_id, $second->id, $this->actor))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $service->operation($first->refill_operation_id, $other))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $service->scan($this->refillData, 'A0000000A', $first->refill_operation_id, $other))->toThrow(ModelNotFoundException::class);
    expect(Products::count())->toBe(2);
});

it('deletes only a newly registered untouched product atomically frees its serial and retains the reversal audit', function () {
    $serial = SerialController::generatepool(1)->first();
    $service = app(ExistingProductsRefillService::class);
    $entry = $service->scan($this->refillData, $serial->serial, null, $this->actor);
    $service->delete($entry->refill_operation_id, $entry->id, $this->actor);
    $service->delete($entry->refill_operation_id, $entry->id, $this->actor);
    expect(Products::count())->toBe(0)->and($serial->fresh()->status)->toBe(Serial::STATUS_FREE)
        ->and($serial->fresh()->products_id)->toBeNull()->and($entry->fresh()->product_id)->toBeNull()
        ->and($entry->fresh()->deleted_by)->toBe($this->actor->id)->and($entry->fresh()->snapshot['serial'])->toBe($serial->serial)
        ->and($entry->fresh()->origin_history['process'])->toBe('Refill registered');
    // A deliberate rescan after a confirmed reversal can reuse the now-Free serial.
    $service->scan($this->refillData, $serial->serial, $entry->refill_operation_id, $this->actor);
    expect(Products::count())->toBe(1)->and(RefillEntry::count())->toBe(2);
});

it('blocks deletion after history movements associations or changes to the product', function (string $mode) {
    $serial = SerialController::generatepool(1)->first();
    $service = app(ExistingProductsRefillService::class);
    $entry = $service->scan($this->refillData, $serial->serial, null, $this->actor);
    $product = $entry->product;
    if ($mode === 'history') {
        $product->logHistory('Moved', 'Movement after refill', 'Stock');
    } elseif ($mode === 'association') {
        traceAssociation($product, traceOrder(23), 'distribution');
    } elseif ($mode === 'location') {
        $product->update(['location_id' => 52]);
    } elseif ($mode === 'cycle') {
        $product->cycles()->create(['cycle_number' => 1, 'source' => 'production']);
    } elseif ($mode === 'installed') {
        Schema::create('installed_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products');
        });
        DB::table('installed_products')->insert(['product_id' => $product->id]);
    } elseif ($mode === 'origin') {
        $product->history()->first()->update(['description' => 'Later correction']);
    } else {
        $product->update(['weight' => 2499]);
    }
    $history = $product->history()->get()->toArray();
    expect(fn () => $service->delete($entry->refill_operation_id, $entry->id, $this->actor))->toThrow(ValidationException::class)
        ->and(Products::count())->toBe(1)->and($serial->fresh()->status)->toBe(Serial::STATUS_USED)
        ->and($product->history()->get()->toArray())->toBe($history);
})->with(['history', 'association', 'location', 'cycle', 'weight', 'installed', 'origin']);

it('rolls back the product serial operation and history if saving an entry fails', function () {
    $serial = SerialController::generatepool(1)->first();
    DB::statement("CREATE TRIGGER fail_refill_entry BEFORE INSERT ON refill_entries BEGIN SELECT RAISE(ABORT, 'test failure'); END");
    expect(fn () => app(ExistingProductsRefillService::class)->scan($this->refillData, $serial->serial, null, $this->actor))->toThrow(QueryException::class)
        ->and(Products::count())->toBe(0)->and(RefillOperation::count())->toBe(0)->and(DB::table('history')->count())->toBe(0)
        ->and($serial->fresh()->status)->toBe(Serial::STATUS_FREE)->and($serial->fresh()->products_id)->toBeNull();
});

it('rolls back the serial history and reversal entry if deleting the product fails', function () {
    $serial = SerialController::generatepool(1)->first();
    $service = app(ExistingProductsRefillService::class);
    $entry = $service->scan($this->refillData, $serial->serial, null, $this->actor);
    DB::statement("CREATE TRIGGER fail_refill_delete BEFORE DELETE ON products BEGIN SELECT RAISE(ABORT, 'test failure'); END");
    expect(fn () => $service->delete($entry->refill_operation_id, $entry->id, $this->actor))->toThrow(QueryException::class)
        ->and(Products::count())->toBe(1)->and($entry->fresh()->deleted_at)->toBeNull()
        ->and($serial->fresh()->status)->toBe(Serial::STATUS_USED)->and(DB::table('history')->count())->toBe(1);
});

it('forbids Refill access and mutations for non Admin users', function (string $role) {
    $this->actor->syncRoles([$role]);
    $service = app(ExistingProductsRefillService::class);
    expect(RefillProd::canAccess())->toBeFalse()
        ->and(fn () => $service->scan($this->refillData, 'A0000000A', null, $this->actor))->toThrow(HttpException::class)
        ->and(fn () => $service->delete(1, 1, $this->actor))->toThrow(HttpException::class)
        ->and(fn () => $service->operations($this->actor))->toThrow(HttpException::class)
        ->and(fn () => $service->close(1, $this->actor))->toThrow(HttpException::class);
})->with(['Worker', 'Manager', 'Customer']);

it('closes common form data and starts independent operations without mixing products', function () {
    $serials = SerialController::generatepool(2);
    $service = app(ExistingProductsRefillService::class);
    $first = $service->scan($this->refillData, $serials[0]->serial, null, $this->actor);
    $change = array_replace($this->refillData, ['asset_id' => 32, 'weight' => 2000]);
    expect(fn () => $service->scan($change, $serials[1]->serial, $first->refill_operation_id, $this->actor))->toThrow(ValidationException::class);
    $service->close($first->refill_operation_id, $this->actor);
    $second = $service->scan($change, $serials[1]->serial, null, $this->actor);
    expect($second->refill_operation_id)->not->toBe($first->refill_operation_id)->and(RefillOperation::count())->toBe(2);
});

it('requires confirmation before deleting through the Refill page', function () {
    $serial = SerialController::generatepool(1)->first();
    $entry = app(ExistingProductsRefillService::class)->scan($this->refillData, $serial->serial, null, $this->actor);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    $page = Livewire\Livewire::test(RefillProd::class)->mountAction('deleteProduct', ['entry' => $entry->id])
        ->assertActionMounted('deleteProduct');
    expect(Products::count())->toBe(1);
    $page->callMountedAction()->assertSee('Products registered: 0');
    expect(Products::count())->toBe(0)->and($serial->fresh()->status)->toBe(Serial::STATUS_FREE);
});

it('keeps Production reservations independent of consumed refill serials', function () {
    $serials = SerialController::generatepool(3);
    app(ExistingProductsRefillService::class)->scan($this->refillData, $serials[0]->serial, null, $this->actor);
    SerialController::reserve(2);
    expect($serials[0]->fresh()->status)->toBe(Serial::STATUS_USED)
        ->and(Serial::where('status', Serial::STATUS_RESERVED)->count())->toBe(2);
});

it('starts a new operation from the page and allows viewing an earlier closed operation', function () {
    $serials = SerialController::generatepool(2);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    $page = Livewire\Livewire::test(RefillProd::class)->fillForm($this->refillData)
        ->set('serialInput', $serials[0]->serial)->call('scan')->assertHasNoErrors();
    $firstId = RefillOperation::first()->id;
    $page->callAction('newOperation')->assertSet('operationId', null)
        ->fillForm(array_replace($this->refillData, ['asset_id' => 32, 'weight' => 2000]))
        ->set('serialInput', $serials[1]->serial)->call('scan')->assertHasNoErrors();
    expect(RefillOperation::count())->toBe(2);
    $page->callAction('viewOperation', ['operation' => $firstId])->assertSet('operationId', $firstId)
        ->assertSee('This operation is closed.')->assertSee($serials[0]->serial)->assertDontSee($serials[1]->serial);
});
