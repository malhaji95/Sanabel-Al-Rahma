<?php

namespace App\Policies;

use App\Models\JobRequest;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class JobRequestPolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->canRead($user, 'view_reports') || $user->isAdmin() || $user->hasRole('council');
    }

    public function view(User $user, JobRequest $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'browse_job_market');
    }

    public function update(User $user, JobRequest $model): bool
    {
        return $this->canWrite($user, 'browse_job_market');
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, JobRequest $model): bool
    {
        return $this->canWrite($user, 'browse_job_market');
    }

    public function forceDelete(User $user, JobRequest $model): bool
    {
        return false;
    }
}
