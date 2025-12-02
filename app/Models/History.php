<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class History extends Model
{
    protected $table = 'history';

    protected $fillable = [
        'products_id',
        'process',
        'date',
        'user_id',
        'location',
        'description',
    ];

    public function product()
    {
        return $this->belongsTo(Products::class, 'products_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
