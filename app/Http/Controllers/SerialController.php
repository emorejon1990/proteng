<?php

namespace App\Http\Controllers;

use App\Models\Products;
use App\Models\Serial;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class SerialController extends Controller
{
    public static function generatepool(int $cant = 100): Collection
    {
        if ($cant < 0) {
            throw new InvalidArgumentException('The quantity cannot be negative.');
        }

        return DB::transaction(function () use ($cant) {
            $serials = new Collection;
            for ($i = 0; $i < $cant; $i++) {
                do {
                    try {
                        // A nested transaction rolls back a collision before retrying.
                        $record = DB::transaction(fn () => Serial::create([
                            'serial' => self::randomSerial(),
                            'status' => Serial::STATUS_FREE,
                            'products_id' => null,
                        ]));
                        break;
                    } catch (UniqueConstraintViolationException $exception) {
                        // Retry only collisions; other database errors must propagate.
                    }
                } while (true);
                $serials->push($record);
            }

            return $serials;
        });
    }

    public static function reserve(int $quantity): void
    {
        if ($quantity < 0) {
            throw new InvalidArgumentException('The quantity cannot be negative.');
        }
        DB::transaction(function () use ($quantity) {
            while ($quantity > 0) {
                $serials = Serial::where('status', Serial::STATUS_FREE)
                    ->whereNull('products_id')->orderBy('id')->limit($quantity)
                    ->lockForUpdate()->get();
                foreach ($serials as $serial) {
                    $serial->update(['status' => Serial::STATUS_RESERVED]);
                }
                $quantity -= $serials->count();
                if ($quantity > 0) {
                    self::generatepool(100);
                }
            }
        });
    }

    public static function release(int $quantity): void
    {
        DB::transaction(function () use ($quantity) {
            $serials = Serial::where('status', Serial::STATUS_RESERVED)
                ->whereNull('products_id')->orderBy('id')->limit($quantity)
                ->lockForUpdate()->get();
            if ($serials->count() !== $quantity) {
                throw new RuntimeException('There are not enough reserved serials to release.');
            }
            foreach ($serials as $serial) {
                $serial->update(['status' => Serial::STATUS_FREE]);
            }
        });
    }

    public static function assemble(Products $product, array $attributes): Products
    {
        return DB::transaction(function () use ($product, $attributes) {
            // Match the lock order used when deleting a work order.
            if ($product->work_order_id) {
                WorkOrder::whereKey($product->work_order_id)
                    ->lockForUpdate()->firstOrFail();
            }
            $product = Products::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $product->fill($attributes);
            if ($product->assambled && empty($product->serial)) {
                $serial = Serial::where('status', Serial::STATUS_RESERVED)
                    ->whereNull('products_id')->orderBy('id')->lockForUpdate()->first();
                if (! $serial) {
                    throw new RuntimeException('There are no reserved serials available.');
                }
                $product->serial = $serial->serial;
                $serial->update([
                    'status' => Serial::STATUS_USED,
                    'products_id' => $product->id,
                ]);
            }
            $product->save();

            return $product;
        });
    }

    public static function serial(): string
    {
        do {
            $serial = self::randomSerial();
        } while (Serial::where('serial', $serial)->exists());

        return $serial;
    }

    public static function randomSerial(): string
    {
        $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

        return $letters[random_int(0, 25)]
            .str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT)
            .$letters[random_int(0, 25)];
    }
}
