<?php

namespace App\Models;

use App\Models\Concerns\PreservesTraceabilityRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use LogicException;

class WorkOrderProduct extends Model
{
    use PreservesTraceabilityRecords;

    public const PURPOSE_PRODUCTION = 'production';

    public const PURPOSE_REVIEW = 'review';

    public const PURPOSE_DISTRIBUTION = 'distribution';

    public const STATE_RECORDED = 'recorded';

    public const STATE_ACTIVE = 'active';

    public const STATE_RESERVED = 'reserved';

    public const STATE_COMPLETED = 'completed';

    public const STATE_RELEASED = 'released';

    protected $fillable = [
        'product_id', 'work_order_id', 'purpose', 'state',
        'associated_by', 'associated_at', 'closed_at',
        'rejected_cycle_id',
        'distribution_serial', 'distribution_asset_id', 'distribution_cycle_id',
    ];

    protected $casts = ['associated_at' => 'datetime', 'closed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(function (self $association) {
            if (! in_array($association->purpose, [self::PURPOSE_PRODUCTION, self::PURPOSE_REVIEW, self::PURPOSE_DISTRIBUTION], true)) {
                throw new InvalidArgumentException('Invalid association purpose.');
            }
            if ($association->exists && $association->isDirty(['product_id', 'work_order_id', 'purpose', 'rejected_cycle_id'])) {
                throw new LogicException('An association cannot be reassigned to another product, order or purpose.');
            }
            if ($association->exists && $association->purpose === self::PURPOSE_DISTRIBUTION
                && $association->isDirty(['distribution_serial', 'distribution_asset_id', 'distribution_cycle_id'])
                && static::whereKey($association->id)->value('state') !== self::STATE_RELEASED) {
                throw new LogicException('A reserved or completed distribution snapshot must be retained.');
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Products::class, 'product_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function distributionAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'distribution_asset_id');
    }

    public function distributionCycle(): BelongsTo
    {
        return $this->belongsTo(ProductCycle::class, 'distribution_cycle_id');
    }

    public function associatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'associated_by');
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(ProductCycle::class);
    }

    public function rejectedCycle(): BelongsTo
    {
        return $this->belongsTo(ProductCycle::class, 'rejected_cycle_id');
    }

    public function repairs(): HasMany
    {
        return $this->hasMany(ProductRepair::class);
    }
}
