<?php

namespace App\Policies;

use App\Models\DisbursementOrder;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class DisbursementOrderPolicy
{
    use DeniesReadOnlyRoles;

    /** Every step of the money path, plus whoever may read the reports. */
    private const STEPS = [
        'confirm_disbursement', 'raise_disbursement_order', 'approve_disbursement',
        'execute_disbursement', 'reconcile_disbursement',
    ];

    public function viewAny(User $user): bool
    {
        foreach (self::STEPS as $key) {
            if ($this->canWrite($user, $key)) {
                return true;
            }
        }

        return $this->canRead($user, 'view_reports') || $user->isAdmin() || $user->hasRole('council');
    }

    public function view(User $user, DisbursementOrder $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'raise_disbursement_order');
    }

    public function update(User $user, DisbursementOrder $model): bool
    {
        // A draft order is the treasurer's to shape. After approval it is
        // frozen, and the service refuses every change to it.
        return ! $model->isFrozen() && $this->canWrite($user, 'raise_disbursement_order');
    }

    /** Nothing is hard-deleted (rule 3), and money leaves a trail. */
    public function delete(User $user, DisbursementOrder $model): bool
    {
        return $model->status === 'draft' && $this->canWrite($user, 'raise_disbursement_order');
    }

    public function forceDelete(User $user, DisbursementOrder $model): bool
    {
        return false;
    }
}
