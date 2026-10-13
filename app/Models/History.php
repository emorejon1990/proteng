<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class History extends Model
{
    protected $table = 'history';

    protected $fillable = [
        'products_id',
        'process',
        'date',
        'user_id',
        'location',
        'description',
        'work_order_id',
        'work_order_product_id',
        'product_cycle_id',
        'product_repair_id',
        'metadata',
    ];

    protected $casts = ['metadata' => 'array'];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function association()
    {
        return $this->belongsTo(WorkOrderProduct::class, 'work_order_product_id');
    }

    public function cycle()
    {
        return $this->belongsTo(ProductCycle::class, 'product_cycle_id');
    }

    public function repair()
    {
        return $this->belongsTo(ProductRepair::class, 'product_repair_id');
    }

    public function product()
    {
        return $this->belongsTo(Products::class, 'products_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
