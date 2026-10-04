<?php

namespace App\Policies;

use App\Models\Beneficiary;
use App\Models\User;
use App\Policies\Concerns\DeniesReadOnlyRoles;
use App\Services\CaseService;

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

    /**
     * A file is opened down the approved chain only — the delegate, the
     * association, the case officer. The system administrator holds every
     * permission because the role is technical, and that is exactly why it does
     * not open a family file: the chain would start at the end that can also
     * approve it.
     */
    public const CANNOT_CREATE_CASE = ['admin'];

    public function create(User $user): bool
    {
        if (in_array($user->role?->key, self::CANNOT_CREATE_CASE, true)) {
            return false;
        }

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

    /** The delegate's field sign-off, inside their own region and nowhere else. */
    public function verifyInField(User $user, Beneficiary $case): bool
    {
        return $this->canWrite($user, 'recommend')
            && $this->inScope($user, $case)
            && in_array($case->status, CaseService::AWAITING_FIELD, true);
    }

    /**
     * The area supervisor's endorsement. Held to the role rather than the
     * permission alone: `recommend` is also a delegate's, and the point of the
     * step is that a second person in a supervising role looked at the file.
     */
    public function endorse(User $user, Beneficiary $case): bool
    {
        return $this->canWrite($user, 'recommend')
            && $this->inScope($user, $case)
            && $case->status === 'verified'
            && $case->field_verified_by !== $user->getKey()
            && ($user->hasRole('area_supervisor', 'case_officer') || $user->isAdmin());
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

        // The association sees the families of the city it works in, not only
        // the ones it referred itself — decided 30 Sep 2026. Its own referrals
        // stay visible even if the file was later moved to another region.
        if ($user->hasRole('association')) {
            return $case->created_by === $user->getKey()
                || ($user->association_id !== null && $case->created_by === $user->association_id)
                || $this->permissions()->coversRegion($user, $case->region_id);
        }

        if ($user->hasRole('beneficiary')) {
            return false;
        }

        return $this->permissions()->coversRegion($user, $case->region_id);
    }
}
