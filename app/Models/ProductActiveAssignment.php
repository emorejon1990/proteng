<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProductActiveAssignment extends Model
{
    protected $primaryKey = 'product_id';

    public $incrementing = false;

    protected $fillable = ['product_id', 'work_order_product_id', 'product_cycle_id', 'updated_by'];

    protected static function booted(): void
    {
        static::updating(function (self $assignment) {
            if ($assignment->isDirty('product_id')) {
                throw new LogicException('An assignment slot cannot be transferred to another product.');
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

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ProductCycle::class, 'product_cycle_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
