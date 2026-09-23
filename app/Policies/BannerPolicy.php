<?php

namespace App\Policies;

use App\Models\Banner;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class BannerPolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        // manage_cms is what the content manager holds; without it here the
        // role could create a post but not open the list it lives in.
        return $this->canWrite($user, 'manage_cms')
            || $this->canRead($user, 'view_reports')
            || $user->isAdmin() || $user->hasRole('council');
    }

    public function view(User $user, Banner $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'manage_cms');
    }

    public function update(User $user, Banner $model): bool
    {
        return $this->canWrite($user, 'manage_cms');
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, Banner $model): bool
    {
        return $this->canWrite($user, 'manage_cms');
    }

    public function forceDelete(User $user, Banner $model): bool
    {
        return false;
    }
}
