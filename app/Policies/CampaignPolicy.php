<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class CampaignPolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->canRead($user, 'view_reports') || $user->isAdmin() || $user->hasRole('council');
    }

    public function view(User $user, Campaign $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'manage_campaigns');
    }

    public function update(User $user, Campaign $model): bool
    {
        return $this->canWrite($user, 'manage_campaigns');
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, Campaign $model): bool
    {
        return $this->canWrite($user, 'manage_campaigns');
    }

    public function forceDelete(User $user, Campaign $model): bool
    {
        return false;
    }
}
