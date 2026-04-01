<?php

namespace App\Policies;

use App\Models\User;
use App\Models\InstallationStep;

class InstallationStepPolicy
{
    public function view(User $user, InstallationStep $step): bool
    {
        if ($user->hasRol(['Admin', 'Manager', 'Inst_Manager'])) {
            return true;
        }

        return $user->hasRol(['Worker'])
            && (int) $step->installation->worker_user_id === (int) $user->id;
    }

    public function update(User $user, InstallationStep $step): bool
    {
        return $this->view($user, $step);
    }

    public function create(User $user): bool
    {
        return $user->hasRol(['Admin', 'Manager', 'Inst_Manager']);
    }

    public function delete(User $user, InstallationStep $step): bool
    {
        return $user->hasRol(['Admin', 'Manager', 'Inst_Manager']);
    }
}
