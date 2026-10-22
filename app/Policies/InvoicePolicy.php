<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\DistributionSelectionService;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['Admin', 'Manager', 'Customer']);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        if ($user->hasRole('Customer')) {
            return $user->customer && (int) $invoice->customer_id === (int) $user->customer->id;
        }

        return $user->hasRole(['Admin', 'Manager']);
    }

    public function classify(User $user, Invoice $invoice): bool
    {
        return ! $user->hasRole('Customer') && $user->hasRole(['Admin', 'Manager']);
    }

    public function printFromDistribution(User $user, Invoice $invoice, WorkOrder $workOrder): bool
    {
        return ! $user->hasRole('Customer')
            && $user->hasRole(['Admin', 'Manager', 'Worker'])
            && $invoice->operation_type === 'distribution'
            && (int) $invoice->work_order_id === (int) $workOrder->id
            && app(DistributionSelectionService::class)->orders()->whereKey($workOrder->id)->exists();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return false;
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Invoice $invoice): bool
    {
        return false;
    }

    public function forceDelete(User $user, Invoice $invoice): bool
    {
        return false;
    }
}
