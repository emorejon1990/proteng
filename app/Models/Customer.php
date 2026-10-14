<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'user_id',
        'quickbooks_id',
        'display_name',
        'email',
        'phone',
        'active',
        'billing_address', 'shipping_address',
    ];

    protected $casts = ['billing_address' => 'array', 'shipping_address' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function installedProducts(): HasMany
    {
        return $this->hasMany(InstalledProduct::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function installations(): HasMany
    {
        return $this->hasMany(Installation::class);
    }
}
