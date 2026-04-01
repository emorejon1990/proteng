<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallationAssignment extends Model
{
    protected $fillable = [
        'installation_id',
        'customer_id',
        'equipment_id',
    ];

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }
}
