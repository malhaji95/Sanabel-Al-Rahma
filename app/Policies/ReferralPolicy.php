<?php

namespace App\Policies;

use App\Models\Referral;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class ReferralPolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->canRead($user, 'view_reports') || $user->isAdmin() || $user->hasRole('council');
    }

    public function view(User $user, Referral $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'verify_referral');
    }

    public function update(User $user, Referral $model): bool
    {
        return $this->canWrite($user, 'verify_referral');
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, Referral $model): bool
    {
        return $this->canWrite($user, 'verify_referral');
    }

    public function forceDelete(User $user, Referral $model): bool
    {
        return false;
    }
}
