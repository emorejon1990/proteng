<?php

namespace App\Models;

use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Products extends Model
{
    protected $table = 'products';

    protected $fillable = [
        'serial',
        'weight',
        'f_weight',
        'assambly_by',
        'assambly_date',
        'assambled',
        'fill_by',
        'fill_date',
        'filled',
        'quality_by',
        'quality_date',
        'qualifiled',
        'status_id',
        'asset_id'
    ];

    protected $casts = [
        'assambled' => 'boolean',
        'filled' => 'boolean',
        'qualifiled' => 'boolean',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function wo(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    public function assambler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assambly_by');
    }

    public function filler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fill_by');
    }

    public function qualityChecker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'quality_by');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function getAssetNameAttribute(): ?string
    {
        return optional(Asset::find($this->asset_id))->name;
    }

    public function getAssamblerNameAttribute(): ?string
    {
        return optional($this->assambler)->name;
    }

    public function getFillerNameAttribute(): ?string
    {
        return optional($this->filler)->name;
    }

    public function getQualityCheckerNameAttribute(): ?string
    {
        return optional($this->qualityChecker)->name;
    }

    public function getStatusNameAttribute(): ?string
    {
        return optional($this->status)->name;
    }

    public function getLocationNameAttribute(): ?string
    {
        return optional($this->location)->name;
    }

    public function history()
    {
        return $this->hasMany(History::class, 'products_id');
    }

    public function logHistory($process, $description = null, $location = null)
    {
        return $this->history()->create([
            'process' => $process,
            'date' => now(),
            'user_id' => Auth::id(),
            'location' => $location,
            'description' => $description,
        ]);
    }


}
