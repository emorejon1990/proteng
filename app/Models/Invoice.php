<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $fillable = [
        'customer_id',
        'quickbooks_id',
        'invoice_number',
        'total',
        'balance',
        'status',
        'issued_at',
        'due_at',
        'metadata',
    ];

    protected $casts = [
        'issued_at' => 'date',
        'due_at' => 'date',
        'metadata' => 'array',
    ];

    /* =====================
     |  RELATIONS
     |=====================*/

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
