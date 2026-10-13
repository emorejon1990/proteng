<?php

namespace App\Models;

use App\Http\Controllers\SerialController;
use App\Http\Controllers\WorkOrderController;
use App\Services\ProductionCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class WorkOrder extends Model
{
    protected $table = 'work_orders';

    public const REVIEW_CAPACITY = 50;

    protected $casts = [
        'review_receiving_open' => 'boolean',
        'review_closed_at' => 'datetime',
    ];

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

    public function originalProducts(): HasMany
    {
        return $this->hasMany(Products::class, 'production_work_order_id');
    }

    public function productAssociations(): HasMany
    {
        return $this->hasMany(WorkOrderProduct::class);
    }

    public function reviewClosedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'review_closed_by');
    }

    public function wc(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class, 'wc_id');
    }

    public function getWCNameAttribute(): ?string
    {
        return optional(WorkCenter::find($this->wc_id))->name;
    }

    public function isReviewOrder(): bool
    {
        $configured = config('production.catalog_ids.review_type');
        if ($configured && (int) $this->type_id === (int) $configured) {
            return true;
        }

        return Schema::hasTable('wo_type') && DB::table('wo_type')->where('id', $this->type_id)
            ->whereRaw('LOWER(TRIM(name)) = ?', ['review'])->exists();
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

    // The fallback supports legacy minimal fixtures without catalog tables.
    // With application catalogs present, IDs must always be verified.
    private static function workflowId(string $key, string $table, int $legacy): int
    {
        return Schema::hasTable($table) ? app(ProductionCatalog::class)->id($key) : $legacy;
    }

    private static function isApprovedStatus(int $status): bool
    {
        if (! Schema::hasTable('wo_status')) {
            return $status === 2;
        }
        if (! config('production.catalog_ids.approved_order_status')
            && ! DB::table('wo_status')->where('id', $status)->whereRaw('LOWER(TRIM(name)) = ?', ['approved'])->exists()) {
            return false;
        }

        return $status === app(ProductionCatalog::class)->id('approved_order_status');
    }

    protected static function booted()
    {
        static::creating(function (WorkOrder $wo) {
            if (empty($wo->name)) {
                if ($wo->type_id == self::workflowId('production_type', 'wo_type', 1) && ! $wo->isReviewOrder()) {
                    $wo->name = WorkOrderController::WOname('WO');
                } elseif ($wo->type_id == self::workflowId('distribution_type', 'wo_type', 2) && ! $wo->isReviewOrder()) {
                    $wo->name = WorkOrderController::WOname('WD');
                }
            }
            if (empty($wo->status_id)) {
                $wo->status_id = self::workflowId('new_order_status', 'wo_status', 1);
            }
        });

        // Approval sends production to its verified assembly center.
        static::updating(function (WorkOrder $wo) {
            if ($wo->isDirty('status_id') && self::isApprovedStatus((int) $wo->status_id)
                && $wo->type_id == self::workflowId('production_type', 'wo_type', 1) && ! $wo->isReviewOrder()) {
                $wo->wc_id = self::workflowId('assemble_center', 'work_centers', 1);
            }
        });

        static::updated(function (WorkOrder $wo) {
            if ($wo->wasChanged('status_id') && self::isApprovedStatus((int) $wo->status_id)
                && $wo->type_id == self::workflowId('production_type', 'wo_type', 1)
                && ! $wo->isReviewOrder()
                && ! $wo->products()->exists()) {
                $quantity = (int) $wo->quant;
                SerialController::reserve($quantity);
                for ($i = 0; $i < $quantity; $i++) {
                    $wo->products()->create([
                        'asset_id' => $wo->asset_id,
                        'location_id' => self::workflowId('production_location', 'locations', 1),
                        'status_id' => self::workflowId('in_process_product_status', 'status', 3),
                    ]);
                }
                Log::info("Se crearon {$quantity} productos para la WorkOrder {$wo->id}");
            }
        });

        static::deleting(function (WorkOrder $wo) {
            if ($wo->type_id == self::workflowId('production_type', 'wo_type', 1) && ! $wo->isReviewOrder()) {
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
