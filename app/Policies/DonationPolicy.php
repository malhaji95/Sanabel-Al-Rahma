<?php

namespace App\Policies;

use App\Models\Donation;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class DonationPolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->hasRole('council') || $user->hasRole('donor');
    }

    public function view(User $user, Donation $donation): bool
    {
        if ($user->isAdmin() || $user->hasRole('council')) {
            return true;
        }

        return $user->donor?->getKey() === $donation->donor_id;
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'donate');
    }

    public function verify(User $user, Donation $donation): bool
    {
        return $this->canWrite($user, 'verify_payment') && $donation->status === 'pending';
    }

    public function reject(User $user, Donation $donation): bool
    {
        return $this->verify($user, $donation);
    }

    public function reverse(User $user, Donation $donation): bool
    {
        return $this->canWrite($user, 'verify_payment') && $donation->isVerified();
    }

    /** Rule 5 — a verified donation is never edited. */
    public function update(User $user, Donation $donation): bool
    {
        return ! $donation->isVerified() && $this->canWrite($user, 'verify_payment');
    }

    public function delete(User $user, Donation $donation): bool
    {
        return false;
    }

    public function forceDelete(User $user, Donation $donation): bool
    {
        return false;
    }
}
