<?php

namespace App\Policies;

use App\Models\Disbursement;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class DisbursementPolicy
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

    public function view(User $user, Disbursement $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'confirm_disbursement');
    }

    public function update(User $user, Disbursement $model): bool
    {
        // A confirmed line may still be corrected; once it is inside an order
        // the order's own steps govern it.
        return $model->status === 'confirmed' && $this->canWrite($user, 'confirm_disbursement');
    }

    /** Nothing is hard-deleted (rule 3), and money leaves a trail. */
    public function delete(User $user, Disbursement $model): bool
    {
        return $model->status === 'confirmed' && $this->canWrite($user, 'confirm_disbursement');
    }

    public function forceDelete(User $user, Disbursement $model): bool
    {
        return false;
    }
}
