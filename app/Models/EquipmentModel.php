<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EquipmentModel extends Model
{
    protected $fillable = ['equipment_brand_id', 'name', 'guide_needs_review'];

    protected $casts = ['guide_needs_review' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            $model->name = Str::squish($model->name);
            $model->name_key = EquipmentBrand::nameKey($model->name);
        });
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(EquipmentBrand::class, 'equipment_brand_id');
    }

    public function templateSteps(): HasMany
    {
        return $this->hasMany(EquipmentModelStep::class)->orderBy('sort_order')->orderBy('id');
    }

    public function manuals(): HasMany
    {
        return $this->hasMany(Manual::class);
    }

    public function machines(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }
}
