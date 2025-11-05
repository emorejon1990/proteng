<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Goods extends Model
{
    public function equipment()
    {
        return $this->belongsToMany(Equipment::class,'equi_goods')
            ->withPivot(['quantity'])
            ->withTimestamps();
    }
}
