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
        'realm_id', 'operation_type', 'operation_status', 'classified_at', 'classified_by', 'work_order_id',
        'billing_address', 'shipping_address', 'currency_code', 'tax_total', 'discount_total',
        'quickbooks_updated_at', 'last_synced_at', 'sync_status', 'sync_error', 'raw_payload', 'operation_snapshot',
    ];

    protected $casts = [
        'issued_at' => 'date',
        'due_at' => 'date',
        'metadata' => 'array',
        'billing_address' => 'array', 'shipping_address' => 'array', 'raw_payload' => 'array',
        'operation_snapshot' => 'array', 'classified_at' => 'datetime',
        'quickbooks_updated_at' => 'datetime', 'last_synced_at' => 'datetime',
        'total' => 'decimal:2', 'balance' => 'decimal:2',
    ];

    /* =====================
     |  RELATIONS
     |=====================*/

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines()
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('line_number');
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function events()
    {
        return $this->hasMany(InvoiceEvent::class);
    }

    public function classifiedBy()
    {
        return $this->belongsTo(User::class, 'classified_by');
    }
}
