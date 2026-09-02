<?php

namespace App\Policies;

use App\Models\Sponsorship;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class SponsorshipPolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->canRead($user, 'view_reports') || $user->isAdmin() || $user->hasRole('council');
    }

    public function view(User $user, Sponsorship $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'donate');
    }

    public function update(User $user, Sponsorship $model): bool
    {
        return $this->canWrite($user, 'donate');
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, Sponsorship $model): bool
    {
        return $this->canWrite($user, 'donate');
    }

    public function forceDelete(User $user, Sponsorship $model): bool
    {
        return false;
    }
}
