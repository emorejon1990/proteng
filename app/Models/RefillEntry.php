<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefillEntry extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'array', 'origin_history' => 'array', 'deleted_at' => 'datetime'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Products::class);
    }
}
