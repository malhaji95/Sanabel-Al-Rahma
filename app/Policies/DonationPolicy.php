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
        // Whoever may verify a transfer must be able to open the queue of
        // transfers waiting for it.
        return $this->canWrite($user, 'verify_payment')
            // T-143 — and whoever turns money into a payment request reads the
            // money that funds it. What they read is narrowed to the gifts
            // that name files, in DonationResource: the association's general
            // money is the treasurer's to allocate.
            || $this->canWrite($user, 'confirm_disbursement')
            || $user->isAdmin() || $user->hasRole('council') || $user->hasRole('donor');
    }

    public function view(User $user, Donation $donation): bool
    {
        if ($user->isAdmin() || $user->hasRole('council') || $this->canWrite($user, 'verify_payment')) {
            return true;
        }

        // T-143 — earmarked money only, for the account that raises the
        // payment requests it pays for.
        if ($this->canWrite($user, 'confirm_disbursement')) {
            return $donation->designation === 'earmarked';
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
