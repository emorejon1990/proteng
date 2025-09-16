<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    protected $table = 'asset';

    public function workOrders()
    {
        return $this->belongsToMany(WorkOrder::class,'wo_ass')
            ->withPivot(['quant'])
            ->withTimestamps();
    }
}
