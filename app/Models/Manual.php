<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Manual extends Model
{
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equipment_id');
    }

    public function getEquipmentNameAttribute(): ?string
    {
        return optional(Equipment::find($this->equipment_id))->brand.' - '.(Equipment::find($this->equipment_id))->model;
    }

}
