<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceLine extends Model
{
    protected $guarded = [];

    protected $casts = ['raw_payload' => 'array', 'quantity' => 'decimal:6', 'unit_price' => 'decimal:6', 'amount' => 'decimal:2'];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function isPhysical(): bool
    {
        return $this->detail_type === 'SalesItemLineDetail' && in_array($this->item_type, ['Inventory', 'NonInventory'], true);
    }
}
