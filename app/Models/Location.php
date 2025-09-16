<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Location extends Model
{
    protected $table = 'locations';

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(WareHouse::class, 'wh');
    }

    public function getWarehouseNameAttribute(): ?string
    {
        return optional($this->warehouse)->name;
    }
}
