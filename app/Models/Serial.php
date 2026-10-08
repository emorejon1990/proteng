<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Serial extends Model
{
    public const STATUS_FREE = 1;

    public const STATUS_RESERVED = 2;

    public const STATUS_USED = 3;

    public const STATUS_LABELS = [
        self::STATUS_FREE => 'Free',
        self::STATUS_RESERVED => 'Reserved',
        self::STATUS_USED => 'Used',
    ];

    protected $table = 'serial';

    protected $fillable = ['serial', 'status', 'products_id'];

    protected $attributes = ['status' => self::STATUS_FREE];

    protected $casts = ['status' => 'integer'];

    public function getStatusNameAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? 'Unknown';
    }

    public function products(): BelongsTo
    {
        return $this->belongsTo(Products::class, 'products_id');
    }
}
