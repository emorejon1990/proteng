<?php

use App\Filament\Pages\Quality;
use App\Livewire\WorkOrderQualityEditor;
use App\Models\History;
use App\Models\ProductCycle;
use App\Models\Products;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderProduct;
use App\Services\ProductionQualityService;
use App\Services\ProductTraceabilityService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../Support/ProductionDatabase.php';

function qualityOrder(): WorkOrder
{
    $order = traceOrder();
    DB::table('work_orders')->where('id', $order->id)->update(['status_id' => 13, 'wc_id' => 24, 'wc_changed_at' => '2026-10-12']);

    return $order->fresh();
}

function qualityProduct(WorkOrder $order, array $attributes = []): Products
{
    return traceProduct($order, array_merge([
        'assambled' => true, 'filled' => true, 'qualified' => false,
        'weight' => 2500, 'f_weight' => null, 'quality_date' => null, 'quality_by' => null,
        'fill_date' => '2026-10-07 23:59:00', 'location_id' => 43, 'status_id' => 53,
    ], $attributes));
}

function qualityActor(bool $authorized = true): User
{
    $user = Mockery::mock(User::class)->makePartial();
    $user->forceFill(['id' => 1, 'name' => 'Operator']);
    $user->shouldReceive('hasRole')->with(['Admin', 'Manager', 'Worker'])->andReturn($authorized);

    return $user;
}

function qualityCycle(Products $product, WorkOrder $order, array $attributes = []): ProductCycle
{
    $association = (new ProductTraceabilityService)->recordProductionOrigin($product, $order, 17);

    return ProductCycle::create(array_merge([
        'product_id' => $product->id, 'work_order_product_id' => $association->id,
        'cycle_number' => 1, 'source' => 'production', 'asset_id' => $product->asset_id,
        'fill_completed' => true, 'fill_weight' => '2500.0000', 'fill_date' => $product->fill_date,
        'waiting_started_on' => '2026-10-07', 'quality_available_on' => '2026-10-12',
    ], $attributes));
}

beforeEach(function () {
    setupTraceabilityDatabase();
    phaseOneUp();
    config(['production.catalog_ids' => []]);
    DB::table('wo_status')->insert([['id' => 13, 'name' => 'inProcess'], ['id' => 16, 'name' => 'Ready']]);
    DB::table('work_centers')->insert([['id' => 22, 'name' => 'Fill'], ['id' => 23, 'name' => 'Waiting'], ['id' => 24, 'name' => 'Quality']]);
    DB::table('locations')->insert([['id' => 43, 'name' => 'Quality'], ['id' => 44, 'name' => 'Stock']]);
    DB::table('status')->insert([['id' => 53, 'name' => 'inProcess'], ['id' => 56, 'name' => 'Ready']]);
    phaseFourUp();
    $this->travelTo(Carbon::parse('2026-10-12 00:01:00'));
});

it('selects the exact normalized serial without writing or selecting a sequential product', function () {
    $order = qualityOrder();
    $a = qualityProduct($order);
    $b = qualityProduct($order, ['asset_id' => 32]);
    $selected = app(ProductionQualityService::class)->select($order->id, ' '.strtolower($b->serial)."\r\n", qualityActor());
    expect($selected->id)->toBe($b->id)->and($selected->asset->id)->toBe(32)
        ->and(History::count())->toBe(0)->and(ProductCycle::count())->toBe(0)
        ->and($a->fresh()->f_weight)->toBeNull();
});

it('rejects blank unknown duplicate foreign unfilled unassembled qualified and misplaced serials', function () {
    $order = qualityOrder();
    $foreign = qualityProduct(qualityOrder());
    $unfilled = qualityProduct($order, ['filled' => false]);
    $unassembled = qualityProduct($order, ['assambled' => false]);
    $qualified = qualityProduct($order, ['qualified' => true]);
    $misplaced = qualityProduct($order, ['location_id' => 44]);
    $duplicate = qualityProduct($order);
    qualityProduct($order, ['serial' => $duplicate->serial]);
    foreach (['', 'Z9999999Z', $foreign->serial, $unfilled->serial, $unassembled->serial, $qualified->serial, $misplaced->serial, $duplicate->serial] as $serial) {
        expect(fn () => app(ProductionQualityService::class)->select($order->id, $serial, qualityActor()))->toThrow(ValidationException::class);
    }
    expect(History::count())->toBe(0);
});

it('checks permission and current order stage on identification measurement and completion', function () {
    $order = qualityOrder();
    $product = qualityProduct($order);
    $service = app(ProductionQualityService::class);
    foreach ([null, qualityActor(false)] as $actor) {
        expect(fn () => $service->select($order->id, $product->serial, $actor))->toThrow(HttpException::class);
        expect(fn () => $service->measure($order->id, $product->id, $product->serial, 2500, $actor))->toThrow(HttpException::class);
        expect(fn () => $service->complete($order->id, $product->id, $product->serial, 2500, $actor))->toThrow(HttpException::class);
    }
    foreach ([['wc_id' => 23], ['status_id' => 11], ['type_id' => 22]] as $change) {
        DB::table('work_orders')->where('id', $order->id)->update(array_merge(['wc_id' => 24, 'status_id' => 13, 'type_id' => 17], $change));
        expect(fn () => $service->complete($order->id, $product->id, $product->serial, 2500, qualityActor()))->toThrow(ValidationException::class);
    }
});

it('compares against actual fill weight with exact inclusive five gram boundaries', function () {
    $order = qualityOrder();
    // Asset B nominal is 2000, but quality compares the real fill weight, not the model.
    $product = qualityProduct($order, ['asset_id' => 32, 'weight' => 2500.1234]);
    $service = app(ProductionQualityService::class);
    foreach (['2497.1234' => '-3.0000', '2495.1234' => '-5.0000', '2505.1234' => '5.0000'] as $weight => $difference) {
        $measurement = $service->measure($order->id, $product->id, $product->serial, $weight, qualityActor());
        expect($measurement['approved'])->toBeTrue()->and($measurement['difference_g'])->toBe($difference);
    }
    foreach (['2495.1233', '2505.1235'] as $weight) {
        expect($service->measure($order->id, $product->id, $product->serial, $weight, qualityActor())['approved'])->toBeFalse();
    }
    expect($product->fresh()->f_weight)->toBeNull()->and(History::count())->toBe(0);
});

it('validates quality weight and refuses an unknown fill weight', function () {
    $order = qualityOrder();
    $product = qualityProduct($order);
    foreach ([null, '', 'abc', true, [], 0, -1, INF, NAN, '2500.12345', '10000000000'] as $weight) {
        expect(fn () => app(ProductionQualityService::class)->measure($order->id, $product->id, $product->serial, $weight, qualityActor()))->toThrow(ValidationException::class);
    }
    DB::table('products')->where('id', $product->id)->update(['weight' => null]);
    expect(fn () => app(ProductionQualityService::class)->select($order->id, $product->serial, qualityActor()))->toThrow(ValidationException::class);
});

it('requires five calendar days even if the order was prematurely moved to quality', function () {
    $order = qualityOrder();
    $product = qualityProduct($order);
    $cycle = qualityCycle($product, $order);
    $this->travelTo(Carbon::parse('2026-10-11 23:59:59'));
    expect(fn () => app(ProductionQualityService::class)->select($order->id, $product->serial, qualityActor()))->toThrow(ValidationException::class);
    $this->travelTo(Carbon::parse('2026-10-12 00:00:00'));
    expect(app(ProductionQualityService::class)->select($order->id, $product->serial, qualityActor())->id)->toBe($product->id);
    $cycle->update(['quality_available_on' => '2026-10-11']);
    expect(fn () => app(ProductionQualityService::class)->select($order->id, $product->serial, qualityActor()))->toThrow(ValidationException::class);
});

it('verifies legacy waiting using the latest fill date and not overwritten station date', function () {
    $order = qualityOrder();
    $a = qualityProduct($order, ['fill_date' => '2026-10-06 12:00:00']);
    $b = qualityProduct($order, ['fill_date' => '2026-10-08 12:00:00']);
    $service = app(ProductionQualityService::class);
    expect(fn () => $service->select($order->id, $a->serial, qualityActor()))->toThrow(ValidationException::class);
    $this->travelTo(Carbon::parse('2026-10-13 00:00:00'));
    expect($service->select($order->id, $a->serial, qualityActor())->id)->toBe($a->id);
    DB::table('products')->where('id', $b->id)->update(['fill_date' => null]);
    expect(fn () => $service->select($order->id, $a->serial, qualityActor()))->toThrow(ValidationException::class);
});

it('approves only the scanned product and preserves production identity cycle and audit', function () {
    $order = qualityOrder();
    $a = qualityProduct($order);
    $b = qualityProduct($order);
    $cycle = qualityCycle($b, $order);
    $result = app(ProductionQualityService::class)->complete($order->id, $b->id, $b->serial, 2495, qualityActor());
    expect($result['completed'])->toBeFalse()->and($a->fresh()->qualified)->toBeFalse()
        ->and($b->fresh()->qualified)->toBeTrue()->and($b->fresh()->f_weight)->toBe(2495.0)
        ->and($b->fresh()->weight)->toBe(2500.0)->and($b->fresh()->location_id)->toBe(44)
        ->and($b->fresh()->status_id)->toBe(56)->and($b->fresh()->work_order_id)->toBeNull()
        ->and($b->fresh()->production_work_order_id)->toBe($order->id)
        ->and($cycle->fresh()->quality_result)->toBe('approved')->and($cycle->fresh()->quality_weight)->toBe('2495.0000')
        ->and($b->history()->first()->metadata['difference_g'])->toBe('-5.0000')
        ->and($b->fresh()->activeAssignment->work_order_product_id)->toBeNull()
        ->and($order->fresh()->wc_id)->toBe(24);
});

it('requires confirmation of the exact rejected measurement and prevents repeated rejection', function () {
    $order = qualityOrder();
    $product = qualityProduct($order);
    qualityProduct($order);
    $service = app(ProductionQualityService::class);
    $measurement = $service->measure($order->id, $product->id, $product->serial, 2491, qualityActor());
    expect(fn () => $service->complete($order->id, $product->id, $product->serial, 2491, qualityActor()))->toThrow(ValidationException::class);
    expect(fn () => $service->complete($order->id, $product->id, $product->serial, 2490, qualityActor(), $measurement))->toThrow(ValidationException::class);
    expect(fn () => $service->complete($order->id, $product->id, $product->serial, 2500, qualityActor(), $measurement))->toThrow(ValidationException::class);
    expect($product->fresh()->f_weight)->toBeNull()->and(ProductCycle::count())->toBe(0)->and(History::count())->toBe(0);
    $service->complete($order->id, $product->id, $product->serial, 2491, qualityActor(), $measurement);
    expect($product->fresh()->qualified)->toBeFalse()->and($product->fresh()->wo->isReviewOrder())->toBeTrue()
        ->and($product->fresh()->location_id)->toBe(43)->and($product->fresh()->status_id)->toBe(53)
        ->and($product->cycles()->first()->quality_completed)->toBeTrue()
        ->and($product->cycles()->first()->quality_result)->toBe('rejected')
        ->and($product->history()->first()->metadata['review_pending'])->toBeFalse();
    expect(fn () => $service->complete($order->id, $product->id, $product->serial, 2491, qualityActor(), $measurement))->toThrow(ValidationException::class);
    expect(History::count())->toBe(2)->and(ProductCycle::count())->toBe(1);
});

it('completes an order only when every current product has a quality outcome', function () {
    $order = qualityOrder();
    $a = qualityProduct($order);
    $b = qualityProduct($order);
    $service = app(ProductionQualityService::class);
    $service->select($order->id, $b->serial, qualityActor());
    $c = qualityProduct($order);
    $measurement = $service->measure($order->id, $a->id, $a->serial, 2490, qualityActor());
    $service->complete($order->id, $a->id, $a->serial, 2490, qualityActor(), $measurement);
    $service->complete($order->id, $b->id, $b->serial, 2500, qualityActor());
    expect($order->fresh()->wc_id)->toBe(24);
    $result = $service->complete($order->id, $c->id, $c->serial, 2500, qualityActor());
    expect($result['completed'])->toBeTrue()->and($order->fresh()->wc_id)->toBeNull()
        ->and($order->fresh()->status_id)->toBe(16)->and($order->fresh()->wc_changed_at)->toBe('2026-10-12')
        ->and($service->pendingProducts($order)->count())->toBe(0)->and($a->fresh()->location_id)->toBe(43);
});

it('preserves legacy quality values in a snapshot before replacing current fields', function () {
    $order = qualityOrder();
    $product = qualityProduct($order, ['f_weight' => 2480, 'quality_date' => '2026-10-01 12:00:00', 'quality_by' => 1]);
    app(ProductionQualityService::class)->complete($order->id, $product->id, $product->serial, 2500, qualityActor());
    $cycle = $product->cycles()->first();
    expect((float) $cycle->legacy_snapshot['f_weight'])->toBe(2480.0)
        ->and($cycle->legacy_snapshot['quality_date'])->toBe('2026-10-01 12:00:00')
        ->and($cycle->source)->toBe('legacy')->and($cycle->quality_weight)->toBe('2500.0000');
});

it('rolls back quality product origin cycle assignment and order on audit failure', function () {
    $order = qualityOrder();
    $product = qualityProduct($order);
    DB::unprepared("CREATE TRIGGER reject_quality_history BEFORE INSERT ON history BEGIN SELECT RAISE(ABORT, 'test audit failure'); END");
    expect(fn () => app(ProductionQualityService::class)->complete($order->id, $product->id, $product->serial, 2500, qualityActor()))->toThrow(QueryException::class);
    expect($product->fresh()->f_weight)->toBeNull()->and($product->fresh()->qualified)->toBeFalse()
        ->and($product->fresh()->work_order_id)->toBe($order->id)
        ->and($product->fresh()->production_work_order_id)->toBeNull()->and(ProductCycle::count())->toBe(0)
        ->and(WorkOrderProduct::count())->toBe(0)->and(History::count())->toBe(0)
        ->and($order->fresh()->wc_id)->toBe(24);
});

it('blocks a completed cycle and an active association to another order', function () {
    $order = qualityOrder();
    $product = qualityProduct($order);
    $cycle = qualityCycle($product, $order);
    $cycle->update(['quality_completed' => true, 'quality_result' => 'rejected']);
    expect(fn () => app(ProductionQualityService::class)->select($order->id, $product->serial, qualityActor()))->toThrow(ValidationException::class);
    $other = qualityProduct($order);
    $association = traceAssociation($other, traceOrder(22), 'review');
    (new ProductTraceabilityService)->claim($association);
    expect(fn () => app(ProductionQualityService::class)->complete($order->id, $other->id, $other->serial, 2500, qualityActor()))->toThrow(ValidationException::class);
});

it('reweighs and cancels rejection in Livewire without changing persisted data', function () {
    $this->actingAs(qualityActor());
    $order = qualityOrder();
    $product = qualityProduct($order);
    Livewire::test(WorkOrderQualityEditor::class, ['workOrder' => $order])
        ->assertSet('selectedProductId', null)
        ->set('serialInput', $product->serial)->call('scanSerial')->assertHasNoErrors()
        ->set('currentProductData.weight', 2491)->call('evaluateWeight')->assertSet('measurement.approved', false)
        ->call('requestReview')->assertSet('confirmingRejection', true)
        ->call('cancelReview')->assertSet('confirmingRejection', false)
        ->call('confirmReview')->assertHasErrors('confirmation')
        ->call('weighAgain')->assertSet('measurement', null)->assertSet('currentProductData.weight', null)
        ->call('receiveScaleWeight', 2500, $product->id)->call('evaluateWeight')->assertSet('measurement.approved', true)
        ->call('saveAndNext')->assertHasNoErrors()->assertSet('selectedProductId', null)
        ->assertSet('serialInput', '')->assertDispatched('quality-saved');
    expect($product->fresh()->qualified)->toBeTrue()->and(History::count())->toBe(1);
});

it('freezes evaluated readings and requires a new confirmation after a weight change', function () {
    $this->actingAs(qualityActor());
    $order = qualityOrder();
    $product = qualityProduct($order);
    $component = Livewire::test(WorkOrderQualityEditor::class, ['workOrder' => $order])
        ->set('serialInput', $product->serial)->call('scanSerial')
        ->set('currentProductData.weight', 2491)->call('requestReview')
        ->call('receiveScaleWeight', 2500, $product->id)->assertSet('currentProductData.weight', 2491)
        ->set('currentProductData.weight', 2490)->assertSet('measurement', null)->assertSet('confirmingRejection', false)
        ->call('confirmReview')->assertHasErrors('confirmation');
    expect($product->fresh()->f_weight)->toBeNull()->and(History::count())->toBe(0);
    $component->call('requestReview')->call('confirmReview')->assertHasNoErrors()->assertSet('selectedProductId', null);
    expect($product->cycles()->first()->quality_weight)->toBe('2490.0000')->and(History::count())->toBe(2);
});

it('ignores stale product readings clears invalid scans and locks confirmation state', function () {
    $this->actingAs(qualityActor());
    $order = qualityOrder();
    $a = qualityProduct($order);
    $b = qualityProduct($order);
    $component = Livewire::test(WorkOrderQualityEditor::class, ['workOrder' => $order])
        ->set('serialInput', $a->serial)->call('scanSerial')
        ->set('serialInput', $b->serial)->call('scanSerial')
        ->call('receiveScaleWeight', 2500, $a->id)->assertSet('currentProductData.weight', null)
        ->set('serialInput', 'Z9999999Z')->call('scanSerial')->assertHasErrors('serialInput')
        ->assertSet('selectedProductId', null)->call('saveAndNext')->assertHasErrors('serialInput');
    expect(fn () => $component->set('confirmingRejection', true))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('routes camera scans to only their requested order and validates the serial there', function () {
    $this->actingAs(qualityActor());
    $order = qualityOrder();
    $other = qualityOrder();
    $product = qualityProduct($order);
    $foreign = qualityProduct($other);
    Livewire::test(WorkOrderQualityEditor::class, ['workOrder' => $order])
        ->dispatch('quality-camera-scanned', orderId: $other->id, code: $foreign->serial)
        ->assertSet('selectedProductId', null)
        ->dispatch('quality-camera-scanned', orderId: $order->id, code: $foreign->serial)
        ->assertHasErrors('serialInput')->assertSet('selectedProductId', null)
        ->dispatch('quality-camera-scanned', orderId: $order->id, code: $product->serial)
        ->assertHasNoErrors()->assertSet('selectedProductId', $product->id);
});

it('releases the linked distribution only after all products approve quality', function () {
    $parent = traceOrder(23);
    $order = qualityOrder();
    DB::table('work_orders')->where('id', $order->id)->update(['for' => $parent->id]);
    $a = qualityProduct($order);
    $b = qualityProduct($order);
    $service = app(ProductionQualityService::class);
    $service->complete($order->id, $a->id, $a->serial, 2500, qualityActor());
    expect($parent->fresh()->status_id)->toBe(11);
    $service->complete($order->id, $b->id, $b->serial, 2500, qualityActor());
    expect($parent->fresh()->status_id)->toBe(13);
});

it('does not release the linked distribution when any product remains pending review', function () {
    $parent = traceOrder(23);
    $order = qualityOrder();
    DB::table('work_orders')->where('id', $order->id)->update(['for' => $parent->id]);
    $product = qualityProduct($order);
    $service = app(ProductionQualityService::class);
    $measurement = $service->measure($order->id, $product->id, $product->serial, 2490, qualityActor());
    $service->complete($order->id, $product->id, $product->serial, 2490, qualityActor(), $measurement);
    expect($order->fresh()->status_id)->toBe(16)->and($parent->fresh()->status_id)->toBe(11);
});

it('revalidates fill snapshot and waiting after requesting rejection confirmation', function () {
    $order = qualityOrder();
    $product = qualityProduct($order);
    $cycle = qualityCycle($product, $order);
    $service = app(ProductionQualityService::class);
    $measurement = $service->measure($order->id, $product->id, $product->serial, 2491, qualityActor());
    $cycle->update(['fill_weight' => '2501.0000']);
    expect(fn () => $service->complete($order->id, $product->id, $product->serial, 2491, qualityActor(), $measurement))->toThrow(ValidationException::class);
    $cycle->update(['fill_weight' => '2500.0000', 'quality_available_on' => '2026-10-13']);
    expect(fn () => $service->complete($order->id, $product->id, $product->serial, 2491, qualityActor(), $measurement))->toThrow(ValidationException::class);
    expect($product->fresh()->f_weight)->toBeNull()->and(History::count())->toBe(0);
});

it('requires a validated order before accepting camera results on the quality page', function () {
    $this->actingAs(qualityActor());
    $order = qualityOrder();
    $product = qualityProduct($order);
    $page = new Quality;
    expect(fn () => $page->setCode($product->serial))->toThrow(ValidationException::class);
    $page->openScanner($order->id);
    expect($page->scanOrderId)->toBe($order->id);
    expect(fn () => $page->setCode('Z9999999Z'))->toThrow(ValidationException::class);
    $page->setCode(strtolower($product->serial));
    expect($page->scanOrderId)->toBeNull()->and($page->scannedCode)->toBe($product->serial)
        ->and($product->fresh()->f_weight)->toBeNull();
});

it('rolls back the entire confirmed rejection when Review incorporation fails', function () {
    $order = qualityOrder();
    $product = qualityProduct($order);
    $service = app(ProductionQualityService::class);
    $measurement = $service->measure($order->id, $product->id, $product->serial, 2490, qualityActor());
    DB::unprepared("CREATE TRIGGER reject_quality_review BEFORE INSERT ON history WHEN NEW.process = 'Review assigned' BEGIN SELECT RAISE(ABORT, 'test review failure'); END");
    expect(fn () => $service->complete($order->id, $product->id, $product->serial, 2490, qualityActor(), $measurement))->toThrow(QueryException::class);
    expect($product->fresh()->f_weight)->toBeNull()->and($product->fresh()->quality_date)->toBeNull()
        ->and($product->fresh()->work_order_id)->toBe($order->id)
        ->and($product->fresh()->production_work_order_id)->toBeNull()
        ->and(History::count())->toBe(0)->and(ProductCycle::count())->toBe(0)
        ->and(WorkOrderProduct::count())->toBe(0)
        ->and(WorkOrder::where('type_id', 22)->count())->toBe(0)
        ->and($order->fresh()->wc_id)->toBe(24);
});
