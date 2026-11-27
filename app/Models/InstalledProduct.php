<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstalledProduct extends Model
{
    protected $fillable = [
        'customer_id',
        'product_id',
        'installation_id',
        'equipment_id',
        'work_order_id',
        'registered_by',
        'serial_number',
        'installed_at',
        'warranty_months',
        'warranty_expires_at',
    ];

    protected $casts = [
        'installed_at' => 'date',
        'warranty_expires_at' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $installedProduct) {
            if ($installedProduct->installation_id && ! $installedProduct->warranty_months) {
                return;
            }

            // Si hay installed_at, calcula warranty_expires_at
            if ($installedProduct->installed_at) {
                $months = (int) ($installedProduct->warranty_months ?: 0);

                $installedProduct->warranty_expires_at =
                    $installedProduct->installed_at->copy()->addMonths($months);
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Products::class);
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function scopeWithTraceabilityDetails(Builder $query): Builder
    {
        return $query->with(['product.asset', 'product.status', 'product.location', 'equipment', 'customer', 'workOrder', 'registrar']);
    }

    public function isWarrantyExpired(): bool
    {
        return $this->warranty_expires_at
            ? now()->startOfDay()->gt($this->warranty_expires_at->startOfDay())
            : false;
    }

    public function warrantyRemainingHuman(): ?string
    {
        if (! $this->warranty_expires_at) {
            return null;
        }

        if ($this->isWarrantyExpired()) {
            return 'Expired';
        }

        return now()->diffForHumans($this->warranty_expires_at, [
            'parts' => 2,
            'short' => true,
            'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE,
        ]);
    }

    public function warrantyStatus(): string
    {
        if (! $this->warranty_expires_at) {
            return 'unknown';
        }

        if ($this->isWarrantyExpired()) {
            return 'expired';
        }

        // Vence en <= 30 días
        if (now()->diffInDays($this->warranty_expires_at, false) <= 30) {
            return 'expiring';
        }

        return 'active';
    }
}
