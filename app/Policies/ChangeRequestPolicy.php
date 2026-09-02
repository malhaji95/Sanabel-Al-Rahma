<?php

namespace App\Policies;

use App\Models\ChangeRequest;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class ChangeRequestPolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->canRead($user, 'view_reports') || $user->isAdmin() || $user->hasRole('council');
    }

    public function view(User $user, ChangeRequest $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'approve_change');
    }

    public function update(User $user, ChangeRequest $model): bool
    {
        return $this->canWrite($user, 'approve_change');
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, ChangeRequest $model): bool
    {
        return $this->canWrite($user, 'approve_change');
    }

    public function forceDelete(User $user, ChangeRequest $model): bool
    {
        return false;
    }
}
