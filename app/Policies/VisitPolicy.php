<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Visit;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class VisitPolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->canRead($user, 'view_reports') || $user->isAdmin() || $user->hasRole('council');
    }

    public function view(User $user, Visit $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'record_visit');
    }

    public function update(User $user, Visit $model): bool
    {
        return $this->canWrite($user, 'record_visit');
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, Visit $model): bool
    {
        return $this->canWrite($user, 'record_visit');
    }

    public function forceDelete(User $user, Visit $model): bool
    {
        return false;
    }
}
