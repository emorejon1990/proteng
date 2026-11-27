<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class Manual extends Model
{
    protected static function booted(): void
    {
        static::saving(function (self $manual) {
            if ($manual->equipment_model_id && $manual->equipment_id
                && (int) Equipment::whereKey($manual->equipment_id)->value('equipment_model_id') !== (int) $manual->equipment_model_id) {
                throw ValidationException::withMessages(['data.equipment_model_id' => 'The model must match the selected equipment.']);
            }
        });
    }

    public function catalogModel(): BelongsTo
    {
        return $this->belongsTo(EquipmentModel::class, 'equipment_model_id');
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equipment_id');
    }

    public function getEquipmentNameAttribute(): ?string
    {
        if ($this->equipment_model_id && $model = $this->catalogModel) {
            return $model->brand->name.' - '.$model->name;
        }

        return $this->equipment?->selectionLabel();
    }

}
