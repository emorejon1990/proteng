<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WareHouse extends Model
{
    protected $table = 'warehouses';

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
