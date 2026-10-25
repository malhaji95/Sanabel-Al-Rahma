<?php

namespace App\Policies;

use App\Models\ReferralRequest;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

/**
 * T-145 — the askings in front of the health path.
 *
 * Reading them belongs with issuing the cards they ask for, and with the
 * accounts that read a family file at all: a delegate who sat with the
 * household sees what they asked for. Answering one — a card or a refusal —
 * is `issue_referral` and nothing else.
 */
class ReferralRequestPolicy
{
    use DeniesReadOnlyRoles;

    public function viewAny(User $user): bool
    {
        return $this->canRead($user, 'view_full_case') || $this->opensScreen($user, 'issue_referral');
    }

    public function view(User $user, ReferralRequest $request): bool
    {
        return $this->viewAny($user);
    }

    /** A household applies from its own page; staff may apply on its behalf. */
    public function create(User $user): bool
    {
        return $this->canWrite($user, 'issue_referral')
            || $this->canWrite($user, 'record_visit')
            || $this->canWrite($user, 'create_case');
    }

    /** The answer: a card, or a refusal with a reason. */
    public function handle(User $user, ReferralRequest $request): bool
    {
        return $request->isOpen() && $this->canWrite($user, 'issue_referral');
    }

    public function update(User $user, ReferralRequest $request): bool
    {
        return $this->handle($user, $request);
    }

    /** Nothing is hard-deleted (rule 3). */
    public function delete(User $user, ReferralRequest $request): bool
    {
        return false;
    }

    public function forceDelete(User $user, ReferralRequest $request): bool
    {
        return false;
    }
}
