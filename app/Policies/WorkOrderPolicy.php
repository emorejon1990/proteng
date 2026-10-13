<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ProductionCatalog;

class WorkOrderPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['Admin', 'Manager', 'Worker']);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, WorkOrder $workOrder): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, WorkOrder $workOrder): bool
    {
        if ($workOrder->isReviewOrder()) {
            return false;
        }

        return $user->hasRole(['Admin', 'Manager']) && in_array((int) $workOrder->status_id,
            [app(ProductionCatalog::class)->id('new_order_status'), app(ProductionCatalog::class)->id('approved_order_status')], true);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, WorkOrder $workOrder): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, WorkOrder $workOrder): bool
    {
        return true;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, WorkOrder $workOrder): bool
    {
        return false;
    }
}
