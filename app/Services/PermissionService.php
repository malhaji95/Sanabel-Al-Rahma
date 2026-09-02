<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Region;
use App\Models\Scopes\RegionScope;
use App\Models\User;

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
        'file_complaint', 'handle_complaint', 'manage_cms', 'manage_users',
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

        return $user->role->permissions()->where('key', $permissionKey)->exists();
    }

    public function scopeFor(?User $user, string $permissionKey): ?string
    {
        $permission = $user?->role?->permissions()->where('key', $permissionKey)->first();

        return $permission?->pivot?->scope;
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
