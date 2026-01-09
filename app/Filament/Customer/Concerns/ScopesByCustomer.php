<?php

namespace App\Filament\Customer\Concerns;

use App\Models\Customer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;

trait ScopesByCustomer
{
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = Auth::user();
        $customer = $user?->customer;

        if (! $customer && $user?->email) {
            $customer = Customer::where('email', $user->email)->first();

            if ($customer && $customer->user_id !== $user->id) {
                $customer->user_id = $user->id;
                $customer->save();
            }
        }

        abort_if(! $customer, 403, 'Customer profile not found.');

        return $query->where('customer_id', $customer->id);
    }
}
