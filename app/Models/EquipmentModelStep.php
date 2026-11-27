<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EquipmentModelStep extends Model
{
    protected $fillable = ['title', 'description', 'sort_order', 'is_required', 'img'];

    protected $casts = ['is_required' => 'boolean'];
}
