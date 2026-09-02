<?php

namespace App\Policies;

use App\Models\Media;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class MediaPolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->canRead($user, 'view_reports') || $user->isAdmin() || $user->hasRole('council');
    }

    public function view(User $user, Media $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'upload_media');
    }

    public function update(User $user, Media $model): bool
    {
        return $this->canWrite($user, 'upload_media');
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, Media $model): bool
    {
        return $this->canWrite($user, 'upload_media');
    }

    public function forceDelete(User $user, Media $model): bool
    {
        return false;
    }
}
