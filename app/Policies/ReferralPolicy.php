<?php

namespace App\Policies;

use App\Models\Referral;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class ReferralPolicy
{
    use DeniesReadOnlyRoles;

    /*
     | T-145 — issuing a card and redeeming one are two different acts by two
     | different people. This screen issues them, and it used to ask for
     | `verify_referral`: a key only a service provider holds, and a provider
     | cannot reach this panel. So nobody could issue a card at all, which is
     | why the health path looked like a foundation with nothing on it.
     */
    public function viewAny(User $user): bool
    {
        return $this->opensScreen($user, 'issue_referral');
    }

    public function view(User $user, Referral $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'issue_referral');
    }

    public function update(User $user, Referral $model): bool
    {
        return $this->canWrite($user, 'issue_referral');
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, Referral $model): bool
    {
        return $this->canWrite($user, 'issue_referral');
    }

    public function forceDelete(User $user, Referral $model): bool
    {
        return false;
    }
}
