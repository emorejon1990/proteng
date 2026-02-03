<?php

namespace App\Models;

use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Model;
use App\Http\Controllers\WorkOrderController;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        return $this->hasMany(\App\Models\WorkOrderAsset::class, 'work_order_id');
    }

    public function distributions()
    {
        return $this->belongsToMany(Asset::class,'wo_ass')
            ->withPivot(['quantity'])
            ->withTimestamps();
    }

    protected static function booted()
    {
        static::creating(function (WorkOrder $wo) {
            if (empty($wo->name)) {
                if ($wo->type_id == 1) {
                    $wo->name = WorkOrderController::WOname('WO');
                }elseif ($wo->type_id == 2) {
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
            // Verificar si el status_id cambió a 2
            if ($wo->isDirty('status_id') && $wo->status_id == 2 && $wo->type_id == 1) {
                // Crear productos según la cantidad
                for ($i = 0; $i < $wo->quant; $i++) {
                    Products::create([
                        'asset_id' => $wo->asset_id,
                        'work_order_id' => $wo->id,
                        'location_id' => 1,
                        'status_id' => 3,
                        // Otros campos que quieras definir por defecto
                        //
                        // 'serial' => generarSerial(), // si aplica
                    ]);
                }

                Log::info("Se crearon {$wo->quantity} productos para la WorkOrder {$wo->id}");
            }
        });
    }
}
