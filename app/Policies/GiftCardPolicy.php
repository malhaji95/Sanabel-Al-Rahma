<?php

namespace App\Policies;

use App\Models\GiftCard;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

/**
 * T-241 — the card designs are the association's own artwork, so they
 * belong to the hand that holds the rest of the content.
 */
class GiftCardPolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->opensScreen($user, 'manage_cms');
    }

    public function view(User $user, GiftCard $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'manage_cms');
    }

    public function update(User $user, GiftCard $model): bool
    {
        return $this->canWrite($user, 'manage_cms');
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, GiftCard $model): bool
    {
        return $this->canWrite($user, 'manage_cms');
    }

    public function forceDelete(User $user, GiftCard $model): bool
    {
        return false;
    }
}
