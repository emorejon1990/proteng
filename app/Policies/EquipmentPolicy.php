<?php

namespace App\Policies;

use App\Models\Equipment;
use App\Models\User;

class EquipmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->create($user);
    }

    public function view(User $user, Equipment $equipment): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return ! $user->hasRole('Customer') && $user->hasRole(['Admin', 'Manager']);
    }

    public function update(User $user, Equipment $equipment): bool
    {
        return $this->create($user);
    }

    public function transfer(User $user, Equipment $equipment): bool
    {
        return $this->update($user, $equipment);
    }

    public function delete(User $user, Equipment $equipment): bool
    {
        return $this->update($user, $equipment)
            && ! $equipment->customer_id
            && ! $equipment->installations()->exists()
            && ! $equipment->installedProducts()->exists()
            && ! $equipment->ownershipTransfers()->exists()
            && ! $equipment->installationTemplateSteps()->exists()
            && ! $equipment->manual()->exists()
            && ! $equipment->equi_asset()->exists()
            && ! $equipment->equi_goods()->exists();
    }

    public function restore(User $user, Equipment $equipment): bool
    {
        return false;
    }

    public function forceDelete(User $user, Equipment $equipment): bool
    {
        return false;
    }
}
