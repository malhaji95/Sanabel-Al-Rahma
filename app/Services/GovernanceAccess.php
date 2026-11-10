<?php

namespace App\Services;

use App\Models\GovernanceApprovalRoute;
use App\Models\GovernanceDocument;
use App\Models\User;

/**
 * T-290 — who may do what with one document.
 *
 * Four questions are asked in order, and the first «no» wins:
 *
 *  1. Is this the technical account? Condition 8: «منع مدير النظام التقني من
 *     الاطلاع على الوثائق السرية عبر لوحة الإدارة دون تصريح». The account
 *     that prepares the system decides nothing in it (T-105) and now reads
 *     nothing confidential in it either, unless the association has written
 *     a grant naming it — which is what «دون تصريح» leaves room for.
 *  2. Does the classification allow this role this ability at all?
 *  3. Or has the association granted this one document to this role?
 *  4. And for approving, is this role the route for documents of this kind?
 *
 * Everything here is a read of the association's own rows. Nothing is
 * hard-coded about who they are.
 */
class GovernanceAccess
{
    /**
     * What each classification allows without any grant.
     *
     * `public` is everyone who works here; `internal` is everyone but the
     * accounts that exist to be given things rather than to hold them;
     * `confidential` and `secret` are nobody's by default, and are reached
     * only through a grant or an approval route.
     */
    private const FLOOR = [
        'public' => ['view', 'download'],
        'internal' => ['view', 'download'],
        'confidential' => [],
        'secret' => [],
    ];

    /** Accounts that are served by the system rather than running it. */
    private const OUTSIDE_ROLES = ['donor', 'beneficiary', 'provider', 'association'];

    public function allows(?User $user, GovernanceDocument $document, string $ability): bool
    {
        if (! $user?->is_active || ! $user->role) {
            return false;
        }

        // 1 — the technical account, on anything the association guards.
        if ($user->isAdmin() && $document->isRestricted() && ! $this->granted($user, $document, $ability)) {
            return false;
        }

        // 4 — approving follows the route for the kind, and a grant.
        if ($ability === 'approve') {
            return $this->routed($user, $document) || $this->granted($user, $document, $ability);
        }

        return $this->floorAllows($user, $document, $ability)
            || $this->granted($user, $document, $ability);
    }

    /** The classification's own answer, before any grant. */
    private function floorAllows(User $user, GovernanceDocument $document, string $ability): bool
    {
        if (in_array($user->role->key, self::OUTSIDE_ROLES, true)) {
            return $document->classification === 'public'
                && in_array($ability, self::FLOOR['public'], true);
        }

        return in_array($ability, self::FLOOR[$document->classification] ?? [], true);
    }

    private function granted(User $user, GovernanceDocument $document, string $ability): bool
    {
        return $document->grants()
            ->where('role_id', $user->role->getKey())
            ->where('ability', $ability)
            ->exists();
    }

    /** The association's named approver for documents of this kind. */
    private function routed(User $user, GovernanceDocument $document): bool
    {
        return GovernanceApprovalRoute::query()
            ->where('kind', $document->kind)
            ->where('role_id', $user->role->getKey())
            ->exists();
    }

    /**
     * The roles the association named for this kind, for a screen that has to
     * say who is waited on.
     *
     * @return array<int,string>
     */
    public function approverNames(GovernanceDocument $document): array
    {
        return GovernanceApprovalRoute::with('role')
            ->where('kind', $document->kind)
            ->get()
            ->map(fn (GovernanceApprovalRoute $route) => $route->role?->name_ar)
            ->filter()
            ->values()
            ->all();
    }
}
