<?php

namespace App\Policies;

use App\Models\Complaint;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class ComplaintPolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->canRead($user, 'view_reports') || $user->isAdmin() || $user->hasRole('council');
    }

    public function view(User $user, Complaint $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'handle_complaint');
    }

    public function update(User $user, Complaint $model): bool
    {
        return $this->canWrite($user, 'handle_complaint');
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, Complaint $model): bool
    {
        return $this->canWrite($user, 'handle_complaint');
    }

    public function forceDelete(User $user, Complaint $model): bool
    {
        return false;
    }
}
