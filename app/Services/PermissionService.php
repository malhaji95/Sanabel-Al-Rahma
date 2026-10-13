<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Region;
use App\Models\Role;
use App\Models\Scopes\RegionScope;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * docs/04-permissions.md. Roles and permissions are rows, not code — adding a
 * role later is a data insert. The four hard rules are enforced here so that
 * every Policy inherits them.
 */
class PermissionService
{
    /** Every write ability in the matrix. `council` is denied all of them. */
    public const WRITE_PERMISSIONS = [
        'create_case', 'edit_draft', 'upload_media', 'record_visit', 'recommend',
        'approve_case', 'suspend_graduate', 'override_score', 'edit_config',
        'request_change', 'approve_change', 'merge_duplicates', 'donate',
        'verify_payment', 'manage_campaigns', 'manage_distribution', 'confirm_delivery',
        'manage_own_offers', 'verify_referral', 'publish_job_profile', 'manage_members',
        'file_complaint', 'handle_complaint', 'manage_cms', 'approve_content', 'manage_users',
        // The disbursement path of 6 October, one key per step so no role
        // quietly holds two of them.
        'confirm_disbursement', 'raise_disbursement_order', 'approve_disbursement',
        'execute_disbursement', 'reconcile_disbursement',
        // Anonymisation, two keys for the two people it takes. The association
        // named the holders on 12 October: the chairman of the board of
        // trustees and the executive director.
        'request_anonymise', 'execute_anonymise',
    ];

    public const READ_PERMISSIONS = [
        'view_full_case', 'view_masked_case', 'search_by_national_id', 'browse_job_market', 'view_reports',
    ];

    public function has(?User $user, string $permissionKey): bool
    {
        if (! $user || ! $user->is_active || ! $user->role) {
            return false;
        }

        // Hard rule 1 — council cannot write anything, whatever the pivot says.
        if ($user->isReadOnly() && in_array($permissionKey, self::WRITE_PERMISSIONS, true)) {
            return false;
        }

        return $this->permissionsFor($user)->has($permissionKey);
    }

    public function scopeFor(?User $user, string $permissionKey): ?string
    {
        if (! $user?->role) {
            return null;
        }

        return $this->permissionsFor($user)->get($permissionKey)?->pivot?->scope;
    }

    /**
     * The role's permissions, loaded once and answered from memory afterwards.
     *
     * A panel asks the policy for every row, every column and every action on
     * the page, and each of those used to be its own query -- a list of 25
     * families cost roughly 300. Loading the relation once makes the whole page
     * cost one.
     *
     * @return Collection<string,Permission>
     */
    private function permissionsFor(User $user): Collection
    {
        $user->role->loadMissing('permissions');

        return $user->role->permissions->keyBy('key');
    }

    /** True when the user's region scope covers the given region. */
    public function coversRegion(?User $user, ?int $regionId): bool
    {
        if (! $user || ! $regionId) {
            return false;
        }

        if (! in_array($user->role?->key, RegionScope::SCOPED_ROLES, true)) {
            return true;
        }

        return $user->region_id
            && in_array($regionId, Region::descendantIds($user->region_id), true);
    }

    /**
     * Every role beside every permission, read from the rows themselves.
     *
     * The matrix screen and the training guides both answer «who may do what»,
     * and a second copy of the answer is a copy that drifts. So the question is
     * asked here, of the same table the policies read.
     *
     * @return array{roles: Collection<int,Role>, groups: array<string,array<int,Permission>>}
     */
    public function matrix(): array
    {
        $permissions = Permission::query()->get()->keyBy('key');

        $group = fn (array $keys) => collect($keys)
            ->map(fn (string $key) => $permissions[$key] ?? null)
            ->filter()
            ->values()
            ->all();

        return [
            'roles' => Role::with('permissions')->orderBy('id')->get(),
            'groups' => [
                'write' => $group(self::WRITE_PERMISSIONS),
                'read' => $group(self::READ_PERMISSIONS),
            ],
        ];
    }

    /**
     * The scope a role really holds for a permission, or null.
     *
     * The pivot, after hard rule 1: a read-only role holds no write key
     * whatever the row says, so the matrix shows what `has()` would answer and
     * not what the table stores.
     */
    public function grantedScope(Role $role, Permission $permission): ?string
    {
        if ($role->is_read_only && in_array($permission->key, self::WRITE_PERMISSIONS, true)) {
            return null;
        }

        return $role->permissions->firstWhere('key', $permission->key)?->pivot?->scope;
    }

    /**
     * T-92 — one role's own page of the matrix: what the account may do, with
     * its scope, and what it is refused.
     *
     * The refusals are listed in full rather than summarised. A training guide
     * that lists only the powers reads like a menu; the half that teaches is
     * the half that says «this is not yours», and a trainee who knows it will
     * not ask for a screen that would answer them 403.
     *
     * @return array{read_only: bool, held: array<string,array<int,array{name_ar: string, scope: string}>>, refused: array<string,array<int,string>>}
     */
    public function roleSheet(string $roleKey): array
    {
        $role = Role::with('permissions')->where('key', $roleKey)->first();

        if (! $role) {
            return ['read_only' => false, 'held' => ['write' => [], 'read' => []], 'refused' => ['write' => [], 'read' => []]];
        }

        $matrix = $this->matrix();
        $held = $refused = ['write' => [], 'read' => []];

        foreach ($matrix['groups'] as $group => $permissions) {
            foreach ($permissions as $permission) {
                $scope = $this->grantedScope($role, $permission);

                if ($scope) {
                    $held[$group][] = ['name_ar' => $permission->name_ar, 'scope' => $scope];
                } else {
                    $refused[$group][] = $permission->name_ar;
                }
            }
        }

        return ['read_only' => (bool) $role->is_read_only, 'held' => $held, 'refused' => $refused];
    }

    public static function permissionKeys(): array
    {
        return array_merge(self::WRITE_PERMISSIONS, self::READ_PERMISSIONS);
    }

    public static function ensureSeeded(): void
    {
        foreach (self::permissionKeys() as $key) {
            Permission::firstOrCreate(['key' => $key], ['name_ar' => __('sanabel.permissions.keys.'.$key)]);
        }
    }
}
