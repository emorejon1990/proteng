<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceEvent extends Model
{
    protected $guarded = [];

    protected $casts = ['metadata' => 'array'];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
