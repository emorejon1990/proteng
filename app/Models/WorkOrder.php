<?php

namespace App\Models;

use App\Http\Controllers\SerialController;
use App\Http\Controllers\WorkOrderController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkOrder extends Model
{
    protected $table = 'work_orders';

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_quickbooks_id', 'quickbooks_id');
    }

    public function getAssetNameAttribute(): ?string
    {
        return optional(Asset::find($this->asset_id))->name;
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(WOStatus::class, 'status_id');
    }

    public function getStatusNameAttribute(): ?string
    {
        return optional(WOStatus::find($this->status_id))->name;
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(WOType::class, 'type_id');
    }

    public function getTypeNameAttribute(): ?string
    {
        return optional(WOType::find($this->type_id))->name;
    }

    public function products(): HasMany
    {
        return $this->hasMany(Products::class, 'work_order_id');
    }

    public function wc(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class, 'wc_id');
    }

    public function getWCNameAttribute(): ?string
    {
        return optional(WorkCenter::find($this->wc_id))->name;
    }

    public function workOrderAssets(): HasMany
    {
        return $this->hasMany(WorkOrderAsset::class, 'work_order_id');
    }

    public function distributions()
    {
        return $this->belongsToMany(Asset::class, 'wo_ass')
            ->withPivot(['quantity'])
            ->withTimestamps();
    }

    protected static function booted()
    {
        static::creating(function (WorkOrder $wo) {
            if (empty($wo->name)) {
                if ($wo->type_id == 1) {
                    $wo->name = WorkOrderController::WOname('WO');
                } elseif ($wo->type_id == 2) {
                    $wo->name = WorkOrderController::WOname('WD');
                }
            }
            if (empty($wo->status_id)) {
                $wo->status_id = '1';
            }
        });

        // Asignar wc_id antes de guardar si status_id está cambiando a 2
        static::updating(function (WorkOrder $wo) {
            if ($wo->isDirty('status_id') && $wo->status_id == 2 && $wo->type_id == 1) {
                $wo->wc_id = 1;
            }
        });

        static::updated(function (WorkOrder $wo) {
            if ($wo->wasChanged('status_id') && $wo->status_id == 2 && $wo->type_id == 1
                && ! $wo->products()->exists()) {
                $quantity = (int) $wo->quant;
                SerialController::reserve($quantity);
                for ($i = 0; $i < $quantity; $i++) {
                    $wo->products()->create([
                        'asset_id' => $wo->asset_id,
                        'location_id' => 1,
                        'status_id' => 3,
                    ]);
                }
                Log::info("Se crearon {$quantity} productos para la WorkOrder {$wo->id}");
            }
        });

        static::deleting(function (WorkOrder $wo) {
            if ($wo->type_id == 1) {
                $pending = $wo->products()->where(function ($query) {
                    $query->whereNull('serial')->orWhere('serial', '');
                })->count();
                SerialController::release($pending);
                // Preserve products and used serials when removing their order.
                $wo->products()->update(['work_order_id' => null]);
            }
        });
    }

    public function save(array $options = [])
    {
        return DB::transaction(function () use ($options) {
            if ($this->exists) {
                static::whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            }

            return parent::save($options);
        });
    }

    public function delete()
    {
        return DB::transaction(function () {
            if ($this->exists) {
                static::whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            }

            return parent::delete();
        });
    }
}
