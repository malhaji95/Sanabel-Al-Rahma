<?php

namespace App\Policies;

use App\Models\Beneficiary;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;

class BeneficiaryPolicy
{
    use DeniesReadOnlyRoles;

    /** Hard rule 2 — a donor never receives a full case, only MaskedCaseResource. */
    public function viewAny(User $user): bool
    {
        return $this->canRead($user, 'view_full_case');
    }

    public function view(User $user, Beneficiary $case): bool
    {
        if (! $this->canRead($user, 'view_full_case')) {
            return false;
        }

        return $this->inScope($user, $case);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user, 'create_case');
    }

    public function update(User $user, Beneficiary $case): bool
    {
        if (! $this->canWrite($user, 'edit_draft') || ! $this->inScope($user, $case)) {
            return false;
        }

        // After approval, edits go through change_requests, not straight writes.
        if (in_array($case->status, ['approved', 'published'], true) && ! $user->isAdmin()) {
            return false;
        }

        if ($this->permissions()->scopeFor($user, 'edit_draft') === 'own') {
            return $case->created_by === $user->getKey();
        }

        return true;
    }

    /** Separation of duties — the creator can never be the final approver. */
    public function approve(User $user, Beneficiary $case): bool
    {
        return $this->canWrite($user, 'approve_case')
            && $case->created_by !== $user->getKey();
    }

    public function reject(User $user, Beneficiary $case): bool
    {
        return $this->approve($user, $case);
    }

    public function publish(User $user, Beneficiary $case): bool
    {
        return $this->canWrite($user, 'approve_case') && $case->status === 'approved';
    }

    public function suspend(User $user, Beneficiary $case): bool
    {
        return $this->canWrite($user, 'suspend_graduate');
    }

    public function merge(User $user, Beneficiary $case): bool
    {
        return $this->canWrite($user, 'merge_duplicates');
    }

    public function overrideScore(User $user, Beneficiary $case): bool
    {
        return $this->canWrite($user, 'override_score');
    }

    public function recordVisit(User $user, Beneficiary $case): bool
    {
        return $this->canWrite($user, 'record_visit') && $this->inScope($user, $case);
    }

    public function requestChange(User $user, Beneficiary $case): bool
    {
        return $this->canWrite($user, 'request_change') && $this->inScope($user, $case);
    }

    /** Nothing is ever hard-deleted (rule 3). */
    public function delete(User $user, Beneficiary $case): bool
    {
        return false;
    }

    public function forceDelete(User $user, Beneficiary $case): bool
    {
        return false;
    }

    /**
     * Hard rule 3 — an association sees its own and referred cases only.
     */
    private function inScope(User $user, Beneficiary $case): bool
    {
        if ($user->hasRole('admin', 'case_officer', 'council')) {
            return true;
        }

        if ($user->hasRole('association')) {
            return $case->created_by === $user->getKey()
                || ($user->association_id !== null && $case->created_by === $user->association_id);
        }

        if ($user->hasRole('beneficiary')) {
            return false;
        }

        return $this->permissions()->coversRegion($user, $case->region_id);
    }
}
