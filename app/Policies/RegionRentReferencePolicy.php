<?php

namespace App\Policies;

use App\Models\RegionRentReference;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class RegionRentReferencePolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->canRead($user, 'view_reports') || $user->isAdmin() || $user->hasRole('council');
    }

    public function view(User $user, RegionRentReference $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'edit_config');
    }

    public function update(User $user, RegionRentReference $model): bool
    {
        return $this->canWrite($user, 'edit_config');
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, RegionRentReference $model): bool
    {
        return $this->canWrite($user, 'edit_config');
    }

    public function forceDelete(User $user, RegionRentReference $model): bool
    {
        return false;
    }
}
