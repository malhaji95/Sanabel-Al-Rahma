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
        // manage_cms is what the content manager holds; without it here the
        // role could create a post but not open the list it lives in.
        return $this->opensScreen($user, 'manage_cms');
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
