<?php

namespace App\Policies;

use App\Models\Member;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class MemberPolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->canRead($user, 'view_reports') || $user->isAdmin() || $user->hasRole('council');
    }

    public function view(User $user, Member $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'manage_members');
    }

    public function update(User $user, Member $model): bool
    {
        return $this->canWrite($user, 'manage_members');
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, Member $model): bool
    {
        return $this->canWrite($user, 'manage_members');
    }

    public function forceDelete(User $user, Member $model): bool
    {
        return false;
    }
}
