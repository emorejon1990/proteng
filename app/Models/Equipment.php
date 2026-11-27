<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Equipment extends Model
{
    public function catalogModel(): BelongsTo
    {
        return $this->belongsTo(EquipmentModel::class, 'equipment_model_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function ownershipTransfers(): HasMany
    {
        return $this->hasMany(EquipmentOwnershipTransfer::class)->latest('id');
    }

    public function selectionLabel(): string
    {
        $model = $this->catalogModelForDisplay();
        $name = $model ? $model->brand->name.' '.$model->name : trim($this->brand.' '.$this->model);
        $owner = $this->customer_id ? $this->owner?->display_name : null;

        return implode(' · ', array_filter([$name, $this->identifier, $owner, '#'.$this->id], fn ($part) => filled($part)));
    }

    public function catalogModelForDisplay(): ?EquipmentModel
    {
        return $this->equipment_model_id ? $this->catalogModel : null;
    }

    public function templateStepsForNewInstallation(): HasMany
    {
        return $this->catalogModelForDisplay()?->templateSteps() ?? $this->installationTemplateSteps();
    }

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

    public function installedProducts(): HasMany
    {
        return $this->hasMany(InstalledProduct::class);
    }
}
