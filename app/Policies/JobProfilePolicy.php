<?php

namespace App\Policies;

use App\Models\JobProfile;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class JobProfilePolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->canRead($user, 'view_reports') || $user->isAdmin() || $user->hasRole('council');
    }

    public function view(User $user, JobProfile $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'publish_job_profile');
    }

    public function update(User $user, JobProfile $model): bool
    {
        return $this->canWrite($user, 'publish_job_profile');
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, JobProfile $model): bool
    {
        return $this->canWrite($user, 'publish_job_profile');
    }

    public function forceDelete(User $user, JobProfile $model): bool
    {
        return false;
    }
}
