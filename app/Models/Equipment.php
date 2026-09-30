<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Equipment extends Model
{
    public function equi_goods(): HasMany
    {
        return $this->hasMany(\App\Models\Equi_Goods::class, 'equipment_id');
    }

    public function goods()
    {
        return $this->belongsToMany(Goods::class, 'equi_goods')
            ->withPivot(['quantity'])
            ->withTimestamps();
    }

    public function equi_asset(): HasMany
    {
        return $this->hasMany(\App\Models\Equi_Asset::class, 'equipment_id');
    }

    public function asset()
    {
        return $this->belongsToMany(Asset::class, 'equi_asset')
            ->withPivot(['quantity'])
            ->withTimestamps();
    }

    public function manual(): HasMany
    {
        return $this->hasMany(Manual::class, 'equipment_id');
    }

    public function installationTemplateSteps(): HasMany
    {
        return $this->hasMany(EquipmentInstallationStep::class)->orderBy('sort_order');
    }

    public function installations(): HasMany
    {
        return $this->hasMany(Installation::class);
    }
}
