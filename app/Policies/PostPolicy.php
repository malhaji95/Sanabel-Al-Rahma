<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class PostPolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->canRead($user, 'view_reports') || $user->isAdmin() || $user->hasRole('council');
    }

    public function view(User $user, Post $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'manage_cms');
    }

    public function update(User $user, Post $model): bool
    {
        return $this->canWrite($user, 'manage_cms');
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, Post $model): bool
    {
        return $this->canWrite($user, 'manage_cms');
    }

    public function forceDelete(User $user, Post $model): bool
    {
        return false;
    }
}
