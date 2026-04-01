<?php

namespace App\Models;

use App\Models\InstallationStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Installation extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'equipment_id',
        'customer_id',
        'customer_quickbooks_id',
        'inst_manager_user_id',
        'worker_user_id',
        'status',
        'performed_at',
    ];

    protected $casts = [
        'performed_at' => 'datetime',
    ];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function instManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inst_manager_user_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_user_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(InstallationStep::class)->orderBy('sort_order');
    }

    public function assignment(): HasOne
    {
        return $this->hasOne(InstallationAssignment::class);
    }
}
