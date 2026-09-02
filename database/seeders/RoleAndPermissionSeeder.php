<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Services\PermissionService;
use Illuminate\Database\Seeder;

/**
 * docs/04-permissions.md. Nine active roles, as data.
 * Adding a role later is an insert, not a rewrite.
 */
class RoleAndPermissionSeeder extends Seeder
{
    /** role key => [permission key => scope]. Mirrors the matrix in docs/04-permissions.md. */
    private const MATRIX = [
        'beneficiary' => [
            'view_full_case' => 'own',
            'publish_job_profile' => 'own',
            'file_complaint' => 'own',
        ],
        'delegate' => [
            'create_case' => 'all', 'edit_draft' => 'own', 'upload_media' => 'all',
            'record_visit' => 'area', 'recommend' => 'area', 'view_full_case' => 'area',
            'search_by_national_id' => 'area', 'request_change' => 'area',
            'confirm_delivery' => 'area', 'publish_job_profile' => 'area',
            'file_complaint' => 'own', 'view_reports' => 'own',
        ],
        'area_supervisor' => [
            'create_case' => 'area', 'edit_draft' => 'area', 'upload_media' => 'area',
            'record_visit' => 'area', 'recommend' => 'area', 'view_full_case' => 'area',
            'search_by_national_id' => 'area', 'request_change' => 'area',
            'confirm_delivery' => 'area', 'file_complaint' => 'own', 'view_reports' => 'area',
        ],
        'case_officer' => [
            'create_case' => 'all', 'edit_draft' => 'all', 'upload_media' => 'all',
            'recommend' => 'all', 'view_full_case' => 'all', 'search_by_national_id' => 'all',
            'request_change' => 'all', 'confirm_delivery' => 'all', 'publish_job_profile' => 'all',
            'file_complaint' => 'own', 'view_reports' => 'all',
        ],
        'association' => [
            'create_case' => 'own', 'edit_draft' => 'own', 'upload_media' => 'own',
            'view_full_case' => 'own', 'search_by_national_id' => 'own', 'request_change' => 'own',
            'confirm_delivery' => 'own', 'publish_job_profile' => 'own',
            'file_complaint' => 'own', 'view_reports' => 'own',
        ],
        'donor' => [
            'donate' => 'own', 'view_masked_case' => 'all', 'browse_job_market' => 'all',
            'file_complaint' => 'own', 'view_reports' => 'own',
        ],
        'service_provider' => [
            'manage_own_offers' => 'own', 'verify_referral' => 'own', 'confirm_delivery' => 'own',
            'file_complaint' => 'own', 'view_reports' => 'own',
        ],
        'admin' => 'all',
        // Hard rule 1 — council is read-only. It holds read permissions only, and
        // PermissionService denies every write key regardless of what is stored.
        'council' => [
            'view_full_case' => 'all', 'view_masked_case' => 'all',
            'search_by_national_id' => 'all', 'browse_job_market' => 'all', 'view_reports' => 'all',
        ],
    ];

    private const NAMES = [
        'beneficiary' => 'مستفيد', 'delegate' => 'مندوب', 'area_supervisor' => 'مشرف منطقة',
        'case_officer' => 'مسؤول الحالات', 'association' => 'جمعية', 'donor' => 'متبرع',
        'service_provider' => 'مزود خدمة', 'admin' => 'مدير النظام', 'council' => 'مجلس الإدارة',
    ];

    public function run(): void
    {
        foreach (PermissionService::permissionKeys() as $key) {
            Permission::firstOrCreate(['key' => $key], ['name_ar' => __('sanabel.permissions.keys.'.$key)]);
        }

        $permissions = Permission::pluck('id', 'key');

        foreach (self::MATRIX as $roleKey => $grants) {
            $role = Role::updateOrCreate(
                ['key' => $roleKey],
                ['name_ar' => self::NAMES[$roleKey], 'is_read_only' => $roleKey === 'council'],
            );

            $sync = $grants === 'all'
                ? $permissions->mapWithKeys(fn ($id) => [$id => ['scope' => 'all']])->all()
                : collect($grants)
                    ->mapWithKeys(fn ($scope, $key) => [$permissions[$key] => ['scope' => $scope]])
                    ->all();

            $role->permissions()->sync($sync);
        }
    }
}
