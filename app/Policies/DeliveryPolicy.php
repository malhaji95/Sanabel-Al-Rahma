<?php

namespace App\Policies;

use App\Models\Delivery;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class DeliveryPolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->opensScreen($user, 'confirm_delivery');
    }

    public function view(User $user, Delivery $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'confirm_delivery');
    }

    public function update(User $user, Delivery $model): bool
    {
        return $this->canWrite($user, 'confirm_delivery');
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, Delivery $model): bool
    {
        return $this->canWrite($user, 'confirm_delivery');
    }

    public function forceDelete(User $user, Delivery $model): bool
    {
        return false;
    }
}
