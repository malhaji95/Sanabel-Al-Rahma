<?php

namespace App\Policies;

use App\Models\RegionRate;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class RegionRatePolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->canRead($user, 'view_reports') || $user->isAdmin() || $user->hasRole('council');
    }

    public function view(User $user, RegionRate $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'edit_config');
    }

    public function update(User $user, RegionRate $model): bool
    {
        return $this->canWrite($user, 'edit_config');
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, RegionRate $model): bool
    {
        return $this->canWrite($user, 'edit_config');
    }

    public function forceDelete(User $user, RegionRate $model): bool
    {
        return false;
    }
}
