<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EquipmentBrand extends Model
{
    protected $fillable = ['name'];

    protected static function booted(): void
    {
        static::saving(function (self $brand) {
            $brand->name = Str::squish($brand->name);
            $brand->name_key = self::nameKey($brand->name);
        });
    }

    public static function nameKey(string $name): string
    {
        return hash('sha256', Str::lower(Str::squish($name)));
    }

    public function models(): HasMany
    {
        return $this->hasMany(EquipmentModel::class);
    }
}
