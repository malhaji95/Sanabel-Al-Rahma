<?php

namespace App\Policies;

use App\Models\ContentCard;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

/** The home page is content, so it follows `manage_cms` like every page and post. */
class ContentCardPolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->canWrite($user, 'manage_cms')
            || $this->canRead($user, 'view_reports')
            || $user->isAdmin() || $user->hasRole('council');
    }

    public function view(User $user, ContentCard $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'manage_cms');
    }

    public function update(User $user, ContentCard $model): bool
    {
        return $this->canWrite($user, 'manage_cms');
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, ContentCard $model): bool
    {
        return $this->canWrite($user, 'manage_cms');
    }

    public function restore(User $user, ContentCard $model): bool
    {
        return $this->canWrite($user, 'manage_cms');
    }

    public function forceDelete(User $user, ContentCard $model): bool
    {
        return false;
    }
}
