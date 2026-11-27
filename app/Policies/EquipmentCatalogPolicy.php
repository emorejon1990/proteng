<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class EquipmentCatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->create($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return ! $user->hasRole('Customer') && $user->hasRole(['Admin', 'Manager']);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->create($user);
    }
}
