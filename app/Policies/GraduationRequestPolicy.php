<?php

namespace App\Policies;

use App\Models\GraduationRequest;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

/**
 * T-249 — read by the three hands the policy names and by those who
 * oversee them; written only through the service, which holds the order.
 */
class GraduationRequestPolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->opensScreen($user, 'record_visit', 'recommend', 'suspend_graduate');
    }

    public function view(User $user, GraduationRequest $model): bool
    {
        return $this->viewAny($user);
    }

    /** Raised from the family's own screen, never from a blank form here. */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, GraduationRequest $model): bool
    {
        return false;
    }

    public function delete(User $user, GraduationRequest $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, GraduationRequest $model): bool
    {
        return false;
    }
}
