<?php

namespace App\Policies;

use App\Models\Assessment;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class AssessmentPolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->canRead($user, 'view_reports') || $user->isAdmin() || $user->hasRole('council');
    }

    public function view(User $user, Assessment $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'approve_case');
    }

    public function update(User $user, Assessment $model): bool
    {
        return $this->canWrite($user, 'approve_case');
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, Assessment $model): bool
    {
        return $this->canWrite($user, 'approve_case');
    }

    public function forceDelete(User $user, Assessment $model): bool
    {
        return false;
    }
}
