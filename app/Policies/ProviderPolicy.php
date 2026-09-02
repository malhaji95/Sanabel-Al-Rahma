<?php

namespace App\Policies;

use App\Models\Provider;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class ProviderPolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->canRead($user, 'view_reports') || $user->isAdmin() || $user->hasRole('council');
    }

    public function view(User $user, Provider $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'manage_own_offers');
    }

    public function update(User $user, Provider $model): bool
    {
        return $this->canWrite($user, 'manage_own_offers');
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, Provider $model): bool
    {
        return $this->canWrite($user, 'manage_own_offers');
    }

    public function forceDelete(User $user, Provider $model): bool
    {
        return false;
    }
}
