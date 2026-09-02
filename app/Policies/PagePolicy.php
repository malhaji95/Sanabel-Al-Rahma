<?php

namespace App\Policies;

use App\Models\Page;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class PagePolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->canRead($user, 'view_reports') || $user->isAdmin() || $user->hasRole('council');
    }

    public function view(User $user, Page $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'manage_cms');
    }

    public function update(User $user, Page $model): bool
    {
        return $this->canWrite($user, 'manage_cms');
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, Page $model): bool
    {
        return $this->canWrite($user, 'manage_cms');
    }

    public function forceDelete(User $user, Page $model): bool
    {
        return false;
    }
}
