<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentOwnershipTransfer extends Model
{
    protected $fillable = ['previous_customer_id', 'new_customer_id', 'user_id', 'reason', 'transferred_at'];

    protected $casts = ['transferred_at' => 'datetime'];

    public function previousCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'previous_customer_id');
    }

    public function newCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'new_customer_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
