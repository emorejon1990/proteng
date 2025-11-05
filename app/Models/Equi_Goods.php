<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class Equi_Goods extends Pivot
{
    protected $table = 'equi_goods';
    public $incrementing = true;      // importante
    protected $primaryKey = 'id';
    protected $fillable = ['equipment_id', 'goods_id', 'quantity'];

    public function goods()
    {
        return $this->belongsTo(Goods::class);
    }

    public function equipment()
    {
        return $this->belongsTo(Equipment::class);
    }
}
