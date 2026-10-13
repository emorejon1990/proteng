<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;
use LogicException;

class Products extends Model
{
    protected $table = 'products';

    protected $fillable = [
        'serial',
        'work_order_id',
        'production_work_order_id',
        'location_id',
        'weight',
        'f_weight',
        'assambly_by',
        'assambly_date',
        'assambled',
        'fill_by',
        'fill_date',
        'filled',
        'quality_by',
        'quality_date',
        'qualified',
        'status_id',
        'asset_id',
    ];

    protected $casts = [
        'assambled' => 'boolean',
        'filled' => 'boolean',
        'qualified' => 'boolean',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function wo(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function productionWorkOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'production_work_order_id');
    }

    public function orderAssociations(): HasMany
    {
        return $this->hasMany(WorkOrderProduct::class, 'product_id');
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(ProductCycle::class, 'product_id')->orderBy('cycle_number');
    }

    public function repairs(): HasMany
    {
        return $this->hasMany(ProductRepair::class, 'product_id');
    }

    public function activeAssignment(): HasOne
    {
        return $this->hasOne(ProductActiveAssignment::class, 'product_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $product) {
            if ($product->isDirty('production_work_order_id')) {
                $original = static::whereKey($product->getKey())->value('production_work_order_id');
                if ($original !== null && (string) $original !== (string) $product->production_work_order_id) {
                    throw new LogicException('The original production order must be retained.');
                }
            }
        });
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    public function assambler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assambly_by');
    }

    public function filler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fill_by');
    }

    public function qualityChecker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'quality_by');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function getAssetNameAttribute(): ?string
    {
        return optional(Asset::find($this->asset_id))->name;
    }

    public function getAssamblerNameAttribute(): ?string
    {
        return optional($this->assambler)->name;
    }

    public function getFillerNameAttribute(): ?string
    {
        return optional($this->filler)->name;
    }

    public function getQualityCheckerNameAttribute(): ?string
    {
        return optional($this->qualityChecker)->name;
    }

    public function getStatusNameAttribute(): ?string
    {
        return optional($this->status)->name;
    }

    public function getLocationNameAttribute(): ?string
    {
        return optional($this->location)->name;
    }

    public function history()
    {
        return $this->hasMany(History::class, 'products_id');
    }

    public function logHistory($process, $description = null, $location = null)
    {
        return $this->history()->create([
            'process' => $process,
            'date' => now(),
            'user_id' => Auth::id(),
            'location' => $location,
            'description' => $description,
        ]);
    }
}
