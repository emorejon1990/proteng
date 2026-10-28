<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RefillOperation extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['data' => 'array', 'closed_at' => 'datetime'];

    public function entries(): HasMany
    {
        return $this->hasMany(RefillEntry::class);
    }
}
