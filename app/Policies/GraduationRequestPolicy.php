<?php

namespace App\Policies;

use App\Models\GraduationRequest;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

/**
 * T-249 — read by the three hands the policy names and by those who
 * oversee them; written only through the service, which holds the order.
 */
class GraduationRequestPolicy
{
    use DeniesReadOnlyRoles;

    /**
     * The two hands that act on this screen, and those who oversee them.
     *
     * Not the delegate who raised the study: they raise it from the
     * family's own file and hear the decision in a notification, so a
     * seventh screen in their panel would be a screen they never act on.
     * `WhatAScreenIsForTest` caught exactly that and was right to.
     */
    public function viewAny(User $user): bool
    {
        return $this->opensScreen($user, 'recommend_graduation', 'suspend_graduate');
    }

    public function view(User $user, GraduationRequest $model): bool
    {
        return $this->viewAny($user);
    }

    /** Raised from the family's own screen, never from a blank form here. */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, GraduationRequest $model): bool
    {
        return false;
    }

    public function delete(User $user, GraduationRequest $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, GraduationRequest $model): bool
    {
        return false;
    }
}
