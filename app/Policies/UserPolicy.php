<?php

namespace App\Policies;

use App\Models\User;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class UserPolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->canRead($user, 'view_reports') || $user->isAdmin() || $user->hasRole('council');
    }

    public function view(User $user, User $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'manage_users');
    }

    public function update(User $user, User $model): bool
    {
        return $this->canWrite($user, 'manage_users');
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, User $model): bool
    {
        return $this->canWrite($user, 'manage_users');
    }

    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }
}
