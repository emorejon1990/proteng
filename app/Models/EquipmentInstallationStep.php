<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentInstallationStep extends Model
{
    protected $fillable = [
        'equipment_id',
        'title',
        'description',
        'sort_order',
        'is_required',
        'img',
    ];

    protected $casts = [
        'is_required' => 'boolean',
    ];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }
}
