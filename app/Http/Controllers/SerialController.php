<?php

namespace App\Http\Controllers;

use App\Models\Products;
use App\Models\Serial;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    public static function assemble(Products $product, array $attributes, string $serialInput = ''): Products
    {
        return DB::transaction(function () use ($product, $attributes, $serialInput) {
            // Match the lock order used when deleting a work order.
            if ($product->work_order_id) {
                WorkOrder::whereKey($product->work_order_id)
                    ->lockForUpdate()->firstOrFail();
            }
            $product = Products::whereKey($product->id)->lockForUpdate()->firstOrFail();
            // Serial assignment is controlled by the scanned value, not mass assignment.
            unset($attributes['serial']);
            $product->fill($attributes);
            $serialInput = strtoupper(trim($serialInput));
            if (! empty($product->serial) && $serialInput !== $product->serial) {
                throw ValidationException::withMessages([
                    'serialInput' => 'Este producto ya tiene un serial asignado.',
                ]);
            }
            if ($product->assambled && empty($product->serial)) {
                $serial = Serial::where('serial', $serialInput)->lockForUpdate()->first();
                if (! $serial || ! in_array($serial->status, [Serial::STATUS_FREE, Serial::STATUS_RESERVED], true)
                    || $serial->products_id !== null) {
                    throw ValidationException::withMessages([
                        'serialInput' => 'El serial debe existir y estar Free o Reserved, sin producto asignado.',
                    ]);
                }
                if ($serial->status === Serial::STATUS_FREE && $product->work_order_id) {
                    // Using a free serial consumes one pending reservation in the global pool.
                    self::release(1);
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
