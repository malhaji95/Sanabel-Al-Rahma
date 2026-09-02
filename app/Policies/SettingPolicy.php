<?php

namespace App\Policies;

use App\Models\Setting;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class SettingPolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->canRead($user, 'view_reports') || $user->isAdmin() || $user->hasRole('council');
    }

    public function view(User $user, Setting $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'edit_config');
    }

    public function update(User $user, Setting $model): bool
    {
        return $this->canWrite($user, 'edit_config');
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, Setting $model): bool
    {
        return $this->canWrite($user, 'edit_config');
    }

    public function forceDelete(User $user, Setting $model): bool
    {
        return false;
    }
}
