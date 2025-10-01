<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;

class WorkOrderAsset extends Pivot
{
    protected $table = 'wo_ass';
    public $incrementing = true;      // importante
    protected $primaryKey = 'id';
    protected $fillable = ['work_order_id', 'asset_id', 'quantity'];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
