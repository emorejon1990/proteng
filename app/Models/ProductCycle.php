<?php

namespace App\Models;

use App\Models\Concerns\PreservesTraceabilityRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class ProductCycle extends Model
{
    use PreservesTraceabilityRecords;

    public const SOURCE_PRODUCTION = 'production';

    public const SOURCE_LEGACY = 'legacy';

    public const SOURCE_REPAIR = 'repair';

    public const RESULT_APPROVED = 'approved';

    public const RESULT_REJECTED = 'rejected';

    protected $fillable = [
        'product_id', 'work_order_product_id', 'cycle_number', 'source', 'asset_id',
        'fill_weight', 'quality_weight', 'fill_completed', 'quality_completed', 'quality_result',
        'fill_by', 'quality_by', 'fill_date', 'quality_date', 'waiting_started_on',
        'quality_available_on', 'completed_at', 'legacy_snapshot', 'product_repair_id',
    ];

    protected $casts = [
        'cycle_number' => 'integer', 'fill_weight' => 'decimal:4', 'quality_weight' => 'decimal:4',
        'fill_completed' => 'boolean', 'quality_completed' => 'boolean',
        'fill_date' => 'datetime', 'quality_date' => 'datetime', 'waiting_started_on' => 'date',
        'quality_available_on' => 'date', 'completed_at' => 'datetime', 'legacy_snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $cycle) {
            $completed = static::whereKey($cycle->id)->where(function ($query) {
                $query->whereNotNull('completed_at')->orWhere('quality_completed', true);
            })->exists();
            if ($completed && $cycle->isDirty()) {
                throw new LogicException('A completed cycle is immutable; create a new cycle.');
            }
            if ($cycle->isDirty(['product_id', 'cycle_number', 'source', 'legacy_snapshot', 'work_order_product_id', 'product_repair_id'])) {
                throw new LogicException('Cycle identity and its original snapshot must be retained.');
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Products::class, 'product_id');
    }

    public function reviewAssociation(): HasOne
    {
        return $this->hasOne(WorkOrderProduct::class, 'rejected_cycle_id');
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(WorkOrderProduct::class, 'work_order_product_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function repair(): BelongsTo
    {
        return $this->belongsTo(ProductRepair::class, 'product_repair_id');
    }

    public function filler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fill_by');
    }

    public function qualityChecker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'quality_by');
    }
}
