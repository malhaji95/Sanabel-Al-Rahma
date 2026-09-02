<?php

namespace App\Policies;

use App\Models\Distribution;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class DistributionPolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->canRead($user, 'view_reports') || $user->isAdmin() || $user->hasRole('council');
    }

    public function view(User $user, Distribution $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'manage_distribution');
    }

    public function update(User $user, Distribution $model): bool
    {
        return $this->canWrite($user, 'manage_distribution');
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, Distribution $model): bool
    {
        return $this->canWrite($user, 'manage_distribution');
    }

    public function forceDelete(User $user, Distribution $model): bool
    {
        return false;
    }
}
