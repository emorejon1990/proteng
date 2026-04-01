<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallationStep extends Model
{
    protected $fillable = [
        'installation_id',
        'title',
        'description',
        'sort_order',
        'is_required',
        'is_done',
        'done_at',
        'notes',
        'img',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_done' => 'boolean',
        'done_at' => 'datetime',
    ];

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class);
    }
}
