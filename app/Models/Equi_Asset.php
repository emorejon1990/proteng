<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class Equi_Asset extends Pivot
{
    protected $table = 'equi_asset';
    public $incrementing = true;      // importante
    protected $primaryKey = 'id';
    protected $fillable = ['equipment_id', 'asset_id', 'quantity'];

    public function equipment()
    {
        return $this->belongsTo(Equipment::class);
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }
}
