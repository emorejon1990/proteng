<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class QuickbooksToken extends Model
{
    protected $table = 'quickbooks_tokens';

    protected $fillable = [
        'realm_id',
        'access_token',
        'refresh_token',
    ];

    // Si guardas tokens encriptados:
    public function setAccessTokenAttribute($value)
    {
        $this->attributes['access_token'] = encrypt($value);
    }

    public function getAccessTokenAttribute($value)
    {
        return $value ? decrypt($value) : null;
    }

    public function setRefreshTokenAttribute($value)
    {
        $this->attributes['refresh_token'] = encrypt($value);
    }

    public function getRefreshTokenAttribute($value)
    {
        return $value ? decrypt($value) : null;
    }
}
