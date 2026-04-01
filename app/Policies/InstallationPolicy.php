<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Installation;

class InstallationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRol(['Admin', 'Manager', 'Inst_Manager']);
    }

    public function view(User $user, Installation $installation): bool
    {
        if ($user->hasRol(['Admin', 'Manager', 'Inst_Manager'])) {
            return true;
        }

        if ($user->hasRol(['Worker'])) {
            return (int) $installation->worker_user_id === (int) $user->id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasRol(['Admin', 'Manager', 'Inst_Manager']);
    }

    public function update(User $user, Installation $installation): bool
    {
        return $user->hasRol(['Admin', 'Manager', 'Inst_Manager']);
    }

    public function delete(User $user, Installation $installation): bool
    {
        return $user->hasRol(['Admin', 'Manager', 'Inst_Manager']);
    }

    public function restore(User $user, Installation $installation): bool
    {
        return false;
    }

    public function forceDelete(User $user, Installation $installation): bool
    {
        return false;
    }
}
