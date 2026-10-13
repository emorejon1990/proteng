<?php

namespace App\Models;

use App\Models\Concerns\PreservesTraceabilityRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class ProductRepair extends Model
{
    use PreservesTraceabilityRecords;

    public const RESULT_PENDING = 'pending';

    public const RESULT_REPAIRED = 'repaired';

    public const RESULT_RECYCLED = 'recycled';

    protected $fillable = [
        'product_id', 'work_order_product_id', 'rejected_cycle_id', 'diagnosis',
        'repair_description', 'recycling_reason', 'result', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = ['reviewed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $repair) {
            $completed = static::whereKey($repair->id)->where(function ($query) {
                $query->whereNotNull('reviewed_at')->orWhere('result', '!=', self::RESULT_PENDING);
            })->exists();
            if (($completed && $repair->isDirty())
                || $repair->isDirty(['product_id', 'work_order_product_id', 'rejected_cycle_id'])) {
                throw new LogicException('Repair identity and completed diagnoses must be retained.');
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Products::class, 'product_id');
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(WorkOrderProduct::class, 'work_order_product_id');
    }

    public function rejectedCycle(): BelongsTo
    {
        return $this->belongsTo(ProductCycle::class, 'rejected_cycle_id');
    }

    public function newCycle(): HasOne
    {
        return $this->hasOne(ProductCycle::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
