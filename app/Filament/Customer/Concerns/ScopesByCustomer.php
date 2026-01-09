<?php

namespace App\Filament\Customer\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;

trait ScopesByCustomer
{
    protected static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $customer = Auth::user()?->customer;

        abort_if(! $customer, 403, 'Customer profile not found.');

        return $query->where('customer_id', $customer->id);
    }
}
