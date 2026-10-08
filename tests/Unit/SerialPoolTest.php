<?php

use App\Http\Controllers\SerialController;
use App\Models\Serial;
use App\Models\WorkOrder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
    Schema::create('work_orders', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->integer('type_id');
        $table->integer('status_id');
        $table->integer('quant');
        $table->integer('wc_id')->nullable();
        $table->integer('asset_id')->nullable();
        $table->timestamps();
    });
    Schema::create('products', function (Blueprint $table) {
        $table->id();
        $table->foreignId('work_order_id')->nullable()->constrained('work_orders');
        $table->integer('asset_id')->nullable();
        $table->integer('location_id')->nullable();
        $table->integer('status_id')->nullable();
        $table->string('serial')->nullable();
        $table->boolean('assambled')->default(false);
        $table->timestamps();
    });
    (require base_path('database/migrations/2026_10_07_171107_create_serial_table.php'))->up();
    (require base_path('database/migrations/2026_10_08_000000_add_serial_pool_constraints.php'))->up();
});

function poolOrder(int $quantity): WorkOrder
{
    $order = new WorkOrder;
    $order->forceFill(['name' => 'Test', 'type_id' => 1, 'status_id' => 1, 'quant' => $quantity])->save();
    $order->status_id = 2;
    $order->save();

    return $order;
}

it('generates free unique serials and replenishes in batches of 100', function () {
    SerialController::generatepool(5);
    SerialController::reserve(120);
    expect(Serial::count())->toBe(205)
        ->and(Serial::where('status', Serial::STATUS_RESERVED)->count())->toBe(120)
        ->and(Serial::where('status', Serial::STATUS_FREE)->count())->toBe(85)
        ->and(Serial::whereNotNull('products_id')->count())->toBe(0);
    foreach (Serial::all() as $serial) {
        expect($serial->serial)->toMatch('/^[A-Z][0-9]{7}[A-Z]$/');
    }
});

it('reserves once, assigns during assembly and releases only unused reservations on deletion', function () {
    $order = poolOrder(3);
    $other = poolOrder(2);
    $order->status_id = 3;
    $order->save();
    $order->status_id = 2;
    $order->save();
    expect($order->products()->count())->toBe(3)
        ->and(Serial::where('status', Serial::STATUS_RESERVED)->count())->toBe(5);
    $product = $order->products()->first();
    SerialController::assemble($product, ['assambled' => false]);
    expect($product->fresh()->serial)->toBeNull();
    $selected = Serial::where('status', Serial::STATUS_RESERVED)->orderByDesc('id')->first();
    $product = SerialController::assemble($product, ['assambled' => true], $selected->serial);
    expect($product->serial)->toBe($selected->serial);
    $serial = $product->serial;
    SerialController::assemble($product, ['assambled' => true], $serial);
    expect($product->fresh()->serial)->toBe($serial)
        ->and(Serial::where('products_id', $product->id)->first()->status_name)->toBe('Used');
    $order->delete();
    expect(Serial::where('status', Serial::STATUS_RESERVED)->count())->toBe(2)
        ->and(Serial::where('status', Serial::STATUS_USED)->count())->toBe(1)
        ->and($product->fresh()->work_order_id)->toBeNull()
        ->and($other->products()->count())->toBe(2);
});

it('rolls back a transition when product creation fails', function () {
    $order = new WorkOrder;
    $order->forceFill(['name' => 'Test', 'type_id' => 1, 'status_id' => 1, 'quant' => 2])->save();
    Schema::drop('products');
    $order->status_id = 2;
    expect(fn () => $order->save())->toThrow(QueryException::class);
    expect($order->fresh()->status_id)->toBe(1)->and(Serial::count())->toBe(0);
});

it('accepts a free serial and releases one reservation', function () {
    $order = poolOrder(2);
    $selected = Serial::where('status', Serial::STATUS_FREE)->first();
    $product = SerialController::assemble($order->products()->first(), ['assambled' => true], strtolower($selected->serial));
    expect($product->serial)->toBe($selected->serial)
        ->and($selected->fresh()->status)->toBe(Serial::STATUS_USED)
        ->and(Serial::where('status', Serial::STATUS_RESERVED)->count())->toBe(1);
    $order->delete();
    expect(Serial::where('status', Serial::STATUS_RESERVED)->count())->toBe(0);
});

it('rejects missing, unknown, used and already linked serials without saving assembly', function () {
    $order = poolOrder(2);
    $products = $order->products()->get();
    $selected = Serial::where('status', Serial::STATUS_RESERVED)->first();
    SerialController::assemble($products[0], ['assambled' => true], $selected->serial);
    foreach (['', 'Z9999999Z', $selected->serial] as $input) {
        expect(fn () => SerialController::assemble($products[1], ['assambled' => true], $input))
            ->toThrow(ValidationException::class);
    }
    $linked = Serial::where('status', Serial::STATUS_FREE)->first();
    $linked->update(['products_id' => $products[0]->id]);
    expect(fn () => SerialController::assemble($products[1], ['assambled' => true], $linked->serial))
        ->toThrow(ValidationException::class);
    expect($products[1]->fresh()->serial)->toBeNull()
        ->and($products[1]->fresh()->assambled)->toBeFalse();
});
