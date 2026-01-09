<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstalledProduct extends Model
{
    protected $fillable = [
        'customer_id',
        'product_id',
        'serial_number',
        'installed_at',
        'warranty_months',
        'warranty_expires_at',
    ];

    protected $casts = [
        'installed_at' => 'date',
        'warranty_expires_at' => 'date',
    ];

    /* =====================
     |  RELATIONS
     |=====================*/

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Products::class);
    }

    /* =====================
     |  ACCESSORS
     |=====================*/

    public function getWarrantyRemainingAttribute(): string
    {
        if (! $this->warranty_expires_at) {
            return 'N/A';
        }

        if (now()->greaterThan($this->warranty_expires_at)) {
            return 'Expired';
        }

        return now()->diffForHumans($this->warranty_expires_at, true);
    }
}
